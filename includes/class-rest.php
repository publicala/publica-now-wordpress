<?php
/**
 * REST routes under publica-now/v1: connect, disconnect, purge, the editor's
 * works picker, and status.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-facing routes. Everything here is authenticated with the wp_rest
 * nonce (cookie auth) and a capability check; nothing is public.
 */
final class Rest {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'publica-now/v1';

	/**
	 * Most items the picker returns in one response.
	 */
	const PICKER_LIMIT = 100;

	/**
	 * Singleton instance.
	 *
	 * @var Rest|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Rest
	 */
	public static function instance(): Rest {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/connect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'creator' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 400;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/disconnect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/works',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'works' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'search'       => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'content_type' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'creator'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);
	}

	/**
	 * Permission: site admins.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( Settings::CAPABILITY );
	}

	/**
	 * Permission: anyone who can edit content (the block editor).
	 *
	 * @return bool
	 */
	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * POST /connect {creator} → {creator, website_matches}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect( WP_REST_Request $request ) {
		$result = Settings::instance()->connect( (string) $request->get_param( 'creator' ) );

		return $this->respond( $result );
	}

	/**
	 * POST /disconnect → {disconnected, previous}.
	 *
	 * @return WP_REST_Response
	 */
	public function disconnect() {
		$previous = Settings::instance()->disconnect();

		return $this->respond(
			array(
				'disconnected' => true,
				'previous'     => $previous,
			)
		);
	}

	/**
	 * POST /purge → {purged, creator|null}. The cache is purged even when
	 * the follow-up profile refresh fails; that failure is reported inline.
	 *
	 * @return WP_REST_Response
	 */
	public function purge() {
		$refreshed = Settings::instance()->refresh();

		$payload = array(
			'purged'  => true,
			'creator' => null,
			'warning' => null,
		);

		if ( is_wp_error( $refreshed ) ) {
			if ( 'publicanow_not_connected' !== $refreshed->get_error_code() ) {
				$payload['warning'] = $refreshed->get_error_message();
			}
		} else {
			$payload['creator'] = $refreshed;
		}

		return $this->respond( $payload );
	}

	/**
	 * GET /works?search=&content_type=&creator= → {items:[{id,title,slug,kind,cover_url,price_label}]}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function works( WP_REST_Request $request ) {
		$result = Catalog::instance()->works(
			array(
				'creator'      => (string) $request->get_param( 'creator' ),
				'content_type' => (string) $request->get_param( 'content_type' ),
				'order'        => 'title',
				'limit'        => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}

		$search = trim( (string) $request->get_param( 'search' ) );
		$items  = array();

		foreach ( $result['items'] as $work ) {
			if ( '' !== $search && ! self::matches( $work, $search ) ) {
				continue;
			}

			$items[] = array(
				'id'          => $work['id'],
				'title'       => $work['title'],
				'slug'        => $work['slug'],
				'kind'        => $work['kind'],
				'cover_url'   => $work['cover_url'],
				'price_label' => self::price_label( $work ),
			);

			if ( count( $items ) >= self::PICKER_LIMIT ) {
				break;
			}
		}

		return $this->respond(
			array(
				'items'  => $items,
				'total'  => (int) $result['total'],
				'source' => $result['source'],
			)
		);
	}

	/**
	 * GET /status → {connected, creator_slug, creator_name}.
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		$slug    = Catalog::instance()->connected_slug();
		$creator = Settings::creator();

		return $this->respond(
			array(
				'connected'    => '' !== $slug,
				'creator_slug' => $slug,
				'creator_name' => null !== $creator && isset( $creator['name'] ) ? (string) $creator['name'] : '',
			)
		);
	}

	/**
	 * Case-insensitive substring match over title, author and slug.
	 *
	 * @param array  $work   Normalised work.
	 * @param string $search Needle.
	 * @return bool
	 */
	private static function matches( array $work, string $search ): bool {
		$haystack = $work['title'] . ' ' . $work['author'] . ' ' . $work['slug'] . ' ' . $work['id'];

		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( $haystack, $search );
		}

		return false !== stripos( $haystack, $search );
	}

	/**
	 * Short price label for the picker ("Free", "$12.99", "R$ 39,90", "12.99 CHF").
	 *
	 * Deliberately local: the picker must work even if Team B's Formatting
	 * class is absent, and this label is only ever seen inside the editor.
	 *
	 * @param array $work Normalised work.
	 * @return string
	 */
	public static function price_label( array $work ): string {
		if ( ! empty( $work['is_free'] ) ) {
			return __( 'Free', 'publica-now' );
		}

		$cents = isset( $work['price_cents'] ) ? $work['price_cents'] : null;

		if ( null === $cents ) {
			return '';
		}

		$currency  = isset( $work['currency'] ) ? strtoupper( (string) $work['currency'] ) : 'USD';
		$zero_dec  = in_array( $currency, array( 'JPY', 'KRW', 'CLP', 'ISK', 'HUF', 'VND' ), true );
		$amount    = $zero_dec ? number_format_i18n( (int) $cents ) : number_format_i18n( ( (int) $cents ) / 100, 2 );
		$prefixes  = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'BRL' => 'R$ ',
			'ARS' => 'AR$ ',
			'MXN' => 'MX$ ',
			'CAD' => 'CA$ ',
			'AUD' => 'A$ ',
			'JPY' => '¥',
			'INR' => '₹',
			'CLP' => 'CLP$ ',
			'COP' => 'COP$ ',
			'PEN' => 'S/ ',
			'UYU' => '$U ',
		);
		$formatted = isset( $prefixes[ $currency ] ) ? $prefixes[ $currency ] . $amount : $amount . ' ' . $currency;

		return $formatted;
	}

	/**
	 * Wrap a result: WP_Error → REST error with the HTTP status from its data.
	 *
	 * @param mixed $result Array payload or WP_Error.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

			// A transport failure (status 0) or an upstream 5xx is a 502 from this site's point of view.
			if ( $status < 400 || $status >= 600 ) {
				$status = 502;
			}

			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array_merge( is_array( $data ) ? $data : array(), array( 'status' => $status ) )
			);
		}

		return new WP_REST_Response( $result, 200 );
	}
}
