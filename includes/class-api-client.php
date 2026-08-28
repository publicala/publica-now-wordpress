<?php
/**
 * HTTP client for the publica.now public read API: OAuth client registration,
 * client-credentials tokens, sandbox switch, and error mapping.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to exactly one host (PUBLICANOW_API_BASE, filterable) using
 * wp_remote_* only. Every failure comes back as a WP_Error with one of the
 * contract codes: publicanow_http, publicanow_oauth, publicanow_not_found,
 * publicanow_rate_limited.
 */
final class Api_Client {

	/**
	 * Option holding the OAuth client (autoload off).
	 */
	const OAUTH_OPTION = 'publicanow_oauth';

	/**
	 * Transient holding the bearer token.
	 */
	const TOKEN_TRANSIENT = 'publicanow_token';

	/**
	 * The only scope the plugin ever asks for. Read-only over public data.
	 */
	const SCOPE = 'catalog:read';

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * Seconds subtracted from expires_in so a token is never used at the
	 * very end of its life (clock skew, slow page).
	 */
	const TOKEN_SAFETY_MARGIN = 300;

	/**
	 * Singleton instance.
	 *
	 * @var Api_Client|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Api_Client
	 */
	public static function instance(): Api_Client {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Base URL without trailing slash.
	 *
	 * The filter exists for staging and for the sandbox; it is deliberately
	 * not a setting so a compromised admin account cannot point the plugin at
	 * an arbitrary host.
	 *
	 * @return string
	 */
	public function base(): string {
		/**
		 * Filters the API base URL.
		 *
		 * @param string $base Default PUBLICANOW_API_BASE.
		 */
		$base = (string) apply_filters( 'publicanow_api_base', PUBLICANOW_API_BASE );
		$base = untrailingslashit( trim( $base ) );

		if ( ! preg_match( '#^https?://[^/\s]+$#i', $base ) ) {
			$base = untrailingslashit( PUBLICANOW_API_BASE );
		}

		return $base;
	}

	/**
	 * Whether requests should be answered from sandbox fixtures.
	 *
	 * @return bool
	 */
	public function is_sandbox(): bool {
		$sandbox = defined( 'PUBLICANOW_SANDBOX' ) && PUBLICANOW_SANDBOX;

		/**
		 * Filters whether the plugin talks to the sandbox.
		 *
		 * @param bool $sandbox Default from the PUBLICANOW_SANDBOX constant.
		 */
		return (bool) apply_filters( 'publicanow_sandbox', $sandbox );
	}

	/**
	 * Stored OAuth client, or null when none has been registered.
	 *
	 * @return array|null
	 */
	public function client() {
		$stored = get_option( self::OAUTH_OPTION );

		if ( is_array( $stored ) && ! empty( $stored['client_id'] ) && ! empty( $stored['client_secret'] ) ) {
			return $stored;
		}

		return null;
	}

	/**
	 * Whether a bearer token is currently cached.
	 *
	 * @return bool
	 */
	public function has_cached_token(): bool {
		$token = get_transient( self::TOKEN_TRANSIENT );

		return is_string( $token ) && '' !== $token;
	}

	/**
	 * Drop the cached token (the next call mints a new one).
	 *
	 * @return void
	 */
	public function forget_token() {
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/**
	 * Drop the OAuth client. The next call re-registers.
	 *
	 * @return void
	 */
	public function forget_client() {
		delete_option( self::OAUTH_OPTION );
	}

	/**
	 * Make sure this site has an OAuth client, registering one if needed.
	 *
	 * Registration is self-serve (RFC 7591) and throttled at 10 per hour per
	 * IP, which is why the client is persisted and reused for the life of
	 * the install.
	 *
	 * @return array|WP_Error The stored client array.
	 */
	public function ensure_client() {
		$existing = $this->client();

		if ( null !== $existing ) {
			return $existing;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? $host : 'localhost';

		$response = wp_remote_post(
			$this->base() . '/oauth/register',
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => $this->user_agent(),
				'headers'    => $this->headers( array( 'Content-Type' => 'application/json' ) ),
				'body'       => wp_json_encode(
					array(
						'client_name' => sprintf( 'WordPress site %s', $host ),
						'scope'       => self::SCOPE,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( $response );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = $this->decode( wp_remote_retrieve_body( $response ) );

		if ( 429 === $status ) {
			return $this->rate_limited( $response, $data );
		}

		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['client_id'] ) || empty( $data['client_secret'] ) ) {
			return new WP_Error(
				'publicanow_oauth',
				$this->oauth_message( __( 'Publica.now refused to register this site as an API client.', 'publica-now' ), $data ),
				array( 'status' => $status )
			);
		}

		$client = array(
			'client_id'     => (string) $data['client_id'],
			'client_secret' => (string) $data['client_secret'],
			'registered_at' => time(),
			'scope'         => isset( $data['scope'] ) ? (string) $data['scope'] : self::SCOPE,
		);

		// delete + add rather than update: add_option is the only way to guarantee autoload "no".
		delete_option( self::OAUTH_OPTION );
		add_option( self::OAUTH_OPTION, $client, '', 'no' );

		return $client;
	}

	/**
	 * A valid bearer token, from the transient or freshly minted.
	 *
	 * @param bool $force Skip the cached token.
	 * @return string|WP_Error
	 */
	public function token( bool $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$client = $this->ensure_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$minted = $this->mint( $client );

		if ( is_wp_error( $minted ) && 'invalid_client' === $this->oauth_error_code( $minted ) ) {
			// The client was revoked or purged server-side: register once more.
			$this->forget_client();
			$client = $this->ensure_client();
			if ( is_wp_error( $client ) ) {
				return $client;
			}
			$minted = $this->mint( $client );
		}

		if ( is_wp_error( $minted ) ) {
			return $minted;
		}

		$expires_in = isset( $minted['expires_in'] ) ? (int) $minted['expires_in'] : HOUR_IN_SECONDS;
		$ttl        = max( 60, $expires_in - self::TOKEN_SAFETY_MARGIN );

		set_transient( self::TOKEN_TRANSIENT, $minted['access_token'], $ttl );

		return $minted['access_token'];
	}

	/**
	 * GET a JSON resource under the API base.
	 *
	 * @param string $path  Path starting with "/", e.g. "/api/v1/public/works".
	 * @param array  $query Query parameters (values are RFC 3986 encoded).
	 * @return array|WP_Error Decoded JSON.
	 */
	public function get( string $path, array $query = array() ) {
		$url = $this->url( $path, $query );

		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->send( $url, $token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			// The token expired early or was revoked: invalidate, re-mint, retry exactly once.
			$this->forget_token();
			$token = $this->token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$response = $this->send( $url, $token );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return $this->handle( $response );
	}

	/**
	 * Revoke the cached token (RFC 7009). Best effort: always forgets the
	 * local copy, returns whether the server acknowledged.
	 *
	 * @return bool
	 */
	public function revoke(): bool {
		$token  = get_transient( self::TOKEN_TRANSIENT );
		$client = $this->client();

		$this->forget_token();

		if ( ! is_string( $token ) || '' === $token || null === $client ) {
			return false;
		}

		$response = wp_remote_post(
			$this->base() . '/oauth/revoke',
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => $this->user_agent(),
				'headers'    => $this->headers( $this->basic_auth( $client ) ),
				'body'       => array(
					'token'           => $token,
					'token_type_hint' => 'access_token',
					'client_id'       => $client['client_id'],
					'client_secret'   => $client['client_secret'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Build an absolute URL under the base.
	 *
	 * @param string $path  Path.
	 * @param array  $query Query args.
	 * @return string
	 */
	private function url( string $path, array $query ): string {
		$url = $this->base() . '/' . ltrim( $path, '/' );

		$query = array_filter(
			$query,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		if ( ! empty( $query ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		return $url;
	}

	/**
	 * Perform one authenticated GET.
	 *
	 * @param string $url   Absolute URL.
	 * @param string $token Bearer token.
	 * @return array|WP_Error Raw wp_remote_get response.
	 */
	private function send( string $url, string $token ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => $this->user_agent(),
				'headers'    => $this->headers( array( 'Authorization' => 'Bearer ' . $token ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( $response );
		}

		return $response;
	}

	/**
	 * Exchange client credentials for a token.
	 *
	 * Credentials go both in the body (client_secret_post, what the server
	 * registered us with) and in a Basic header (client_secret_basic), so the
	 * call keeps working if the server changes its preferred method.
	 *
	 * @param array $client Stored client.
	 * @return array|WP_Error Token response array.
	 */
	private function mint( array $client ) {
		$response = wp_remote_post(
			$this->base() . '/oauth/token',
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => $this->user_agent(),
				'headers'    => $this->headers(
					array_merge(
						array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
						$this->basic_auth( $client )
					)
				),
				'body'       => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $client['client_id'],
					'client_secret' => $client['client_secret'],
					'scope'         => self::SCOPE,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( $response );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = $this->decode( wp_remote_retrieve_body( $response ) );

		if ( 429 === $status ) {
			return $this->rate_limited( $response, $data );
		}

		if ( 200 === $status && is_array( $data ) && ! empty( $data['access_token'] ) ) {
			return $data;
		}

		$oauth_error = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : '';

		return new WP_Error(
			'publicanow_oauth',
			$this->oauth_message( __( 'Publica.now did not issue an access token.', 'publica-now' ), $data ),
			array(
				'status'      => $status,
				'oauth_error' => $oauth_error,
			)
		);
	}

	/**
	 * Turn a raw response into decoded JSON or a contract WP_Error.
	 *
	 * @param array $response wp_remote_* response.
	 * @return array|WP_Error
	 */
	private function handle( array $response ) {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = $this->decode( $body );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $data ) ) {
				return new WP_Error(
					'publicanow_http',
					__( 'Publica.now returned a response the plugin could not read.', 'publica-now' ),
					array( 'status' => $status )
				);
			}

			return $data;
		}

		$detail = $this->problem_detail( $data );

		switch ( $status ) {
			case 401:
			case 403:
				return new WP_Error(
					'publicanow_oauth',
					'' !== $detail ? $detail : __( 'Publica.now rejected the plugin’s credentials.', 'publica-now' ),
					array( 'status' => $status )
				);

			case 404:
				return new WP_Error(
					'publicanow_not_found',
					'' !== $detail ? $detail : __( 'Publica.now has no published record with that name.', 'publica-now' ),
					array( 'status' => 404 )
				);

			case 429:
				return $this->rate_limited( $response, $data );
		}

		return new WP_Error(
			'publicanow_http',
			'' !== $detail
				? $detail
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Publica.now answered with HTTP %d.', 'publica-now' ), $status ),
			array( 'status' => $status )
		);
	}

	/**
	 * Build the rate-limit error, reading Retry-After when present.
	 *
	 * @param array      $response Raw response.
	 * @param array|null $data     Decoded body.
	 * @return WP_Error
	 */
	private function rate_limited( array $response, $data ): WP_Error {
		$retry_after = $this->retry_after( $response );
		$detail      = $this->problem_detail( $data );

		if ( '' === $detail ) {
			$detail = __( 'Publica.now is rate limiting this site. The catalog will refresh shortly.', 'publica-now' );
		}

		return new WP_Error(
			'publicanow_rate_limited',
			$detail,
			array(
				'status'      => 429,
				'retry_after' => $retry_after,
			)
		);
	}

	/**
	 * Parse Retry-After (seconds or HTTP-date) into seconds.
	 *
	 * @param array $response Raw response.
	 * @return int|null
	 */
	private function retry_after( array $response ) {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );

		if ( is_array( $header ) ) {
			$header = reset( $header );
		}

		if ( ! is_string( $header ) || '' === trim( $header ) ) {
			return null;
		}

		$header = trim( $header );

		if ( is_numeric( $header ) ) {
			return max( 0, (int) $header );
		}

		$timestamp = strtotime( $header );

		return false === $timestamp ? null : max( 0, $timestamp - time() );
	}

	/**
	 * Wrap a transport failure (DNS, TLS, timeout) as publicanow_http.
	 *
	 * @param WP_Error $error wp_remote_* error.
	 * @return WP_Error
	 */
	private function transport_error( WP_Error $error ): WP_Error {
		return new WP_Error(
			'publicanow_http',
			sprintf(
				/* translators: %s: transport error message from WordPress. */
				__( 'Could not reach publica.now: %s', 'publica-now' ),
				$error->get_error_message()
			),
			array( 'status' => 0 )
		);
	}

	/**
	 * Human message for an OAuth-endpoint failure (RFC 6749 shape).
	 *
	 * @param string     $fallback Default message.
	 * @param array|null $data     Decoded body.
	 * @return string
	 */
	private function oauth_message( string $fallback, $data ): string {
		if ( is_array( $data ) ) {
			if ( ! empty( $data['error_description'] ) && is_string( $data['error_description'] ) ) {
				return $data['error_description'];
			}
			if ( ! empty( $data['detail'] ) && is_string( $data['detail'] ) ) {
				return $data['detail'];
			}
		}

		return $fallback;
	}

	/**
	 * The OAuth "error" code carried on a publicanow_oauth WP_Error.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private function oauth_error_code( WP_Error $error ): string {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['oauth_error'] ) ? (string) $data['oauth_error'] : '';
	}

	/**
	 * The "detail" (or "title") of an RFC 9457 problem document.
	 *
	 * @param array|null $data Decoded body.
	 * @return string
	 */
	private function problem_detail( $data ): string {
		if ( ! is_array( $data ) ) {
			return '';
		}

		foreach ( array( 'detail', 'error_description', 'title', 'message' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return sanitize_text_field( $data[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Decode a JSON body to an associative array.
	 *
	 * @param string $body Raw body.
	 * @return array|null
	 */
	private function decode( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Common headers plus the sandbox switch.
	 *
	 * @param array $extra Extra headers.
	 * @return array
	 */
	private function headers( array $extra = array() ): array {
		$headers = array( 'Accept' => 'application/json' );

		if ( $this->is_sandbox() ) {
			$headers['X-Publica-Now-Sandbox'] = 'true';
		}

		return array_merge( $headers, $extra );
	}

	/**
	 * HTTP Basic header for client_secret_basic.
	 *
	 * @param array $client Stored client.
	 * @return array
	 */
	private function basic_auth( array $client ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth requires base64.
		return array( 'Authorization' => 'Basic ' . base64_encode( $client['client_id'] . ':' . $client['client_secret'] ) );
	}

	/**
	 * User agent that identifies the plugin and the site.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		return 'PublicaNowWP/' . PUBLICANOW_VERSION . ' (+' . home_url() . ')';
	}
}
