<?php
/**
 * Site Health: a direct "Publica.now connection" test and a debug-info section.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Site Health integration.
 */
final class Site_Health {

	/**
	 * Singleton instance.
	 *
	 * @var Site_Health|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Site_Health
	 */
	public static function instance(): Site_Health {
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
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_info' ) );
	}

	/**
	 * Register the direct test.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['publicanow_connection'] = array(
			'label' => __( 'Publica.now connection', 'publica-now' ),
			'test'  => array( $this, 'test_connection' ),
		);

		return $tests;
	}

	/**
	 * The test: connected? API reachable? cache state?
	 *
	 * @return array Site Health result array.
	 */
	public function test_connection(): array {
		$settings_url = Settings::instance()->page_url();
		$slug         = Catalog::instance()->connected_slug();

		$result = array(
			'label'       => '',
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Publica.now', 'publica-now' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $settings_url ),
				esc_html__( 'Open Publica.now settings', 'publica-now' )
			),
			'test'        => 'publicanow_connection',
		);

		if ( '' === $slug ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Publica.now is not connected', 'publica-now' );
			$result['description'] = '<p>' . esc_html__( 'The plugin is active but no publica.now account is connected, so catalog blocks and shortcodes have nothing to show. Paste your profile URL in the settings to connect.', 'publica-now' ) . '</p>';

			return $result;
		}

		/*
		 * A health check must observe the network, so this bypasses Catalog
		 * (which deliberately hides outages behind the stale copy) and asks
		 * the API directly.
		 */
		$response   = Api_Client::instance()->get( '/api/v1/public/creators/' . rawurlencode( $slug ) );
		$works_name = 'works:' . $slug;
		$has_fresh  = Cache::has( $works_name );
		$stale_age  = Cache::stale_age( $works_name );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();

			if ( 'publicanow_not_found' === $response->get_error_code() ) {
				$result['status']      = 'critical';
				$result['label']       = __( 'The connected publica.now creator is no longer published', 'publica-now' );
				$result['description'] = '<p>' . esc_html(
					sprintf(
						/* translators: %s: creator slug. */
						__( 'publica.now has no published creator under “%s” any more. Catalog blocks will show only a fallback link until you connect a valid profile.', 'publica-now' ),
						$slug
					)
				) . '</p>';

				return $result;
			}

			if ( null !== $stale_age ) {
				$result['status']      = 'recommended';
				$result['label']       = __( 'Publica.now cannot be reached; a cached catalog is being shown', 'publica-now' );
				$result['description'] = '<p>' . esc_html(
					sprintf(
						/* translators: 1: error message, 2: human time difference. */
						__( 'The last request to publica.now failed (%1$s). Visitors still see the catalog cached %2$s ago; it will refresh automatically once publica.now answers again.', 'publica-now' ),
						$message,
						human_time_diff( time() - $stale_age )
					)
				) . '</p>';
			} else {
				$result['status']      = 'critical';
				$result['label']       = __( 'Publica.now cannot be reached and nothing is cached', 'publica-now' );
				$result['description'] = '<p>' . esc_html(
					sprintf(
						/* translators: %s: error message. */
						__( 'The request to publica.now failed (%s) and no cached copy of the catalog exists, so catalog blocks show only a link to your publica.now page. Check that this server can make outbound HTTPS requests to publica.now.', 'publica-now' ),
						$message
					)
				) . '</p>';
			}

			return $result;
		}

		$raw     = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
		$creator = Catalog::instance()->normalise_creator( $raw );

		$result['label']       = __( 'Publica.now is connected', 'publica-now' );
		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: 1: creator name, 2: number of works, 3: cache TTL in minutes. */
				__( 'Connected to %1$s (%2$s published works). Catalog data is cached for %3$s minutes and a 7-day copy is kept for outages.', 'publica-now' ),
				$creator['name'],
				number_format_i18n( (int) $creator['works_count'] ),
				number_format_i18n( (int) round( Cache::ttl() / 60 ) )
			)
		) . '</p>';

		if ( ! $has_fresh ) {
			$result['description'] .= '<p>' . esc_html__( 'No catalog is cached right now; the first visit to a page with a catalog block will fetch it.', 'publica-now' ) . '</p>';
		}

		return $result;
	}

	/**
	 * Debug-info section (Site Health → Info, and the copy-to-clipboard report).
	 *
	 * @param array $info Existing sections.
	 * @return array
	 */
	public function add_debug_info( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$client  = Api_Client::instance();
		$slug    = Catalog::instance()->connected_slug();
		$creator = Settings::creator();
		$oauth   = $client->client();

		$stale_age = '' !== $slug ? Cache::stale_age( 'works:' . $slug ) : null;

		$info['publica-now'] = array(
			'label'       => __( 'Publica.now', 'publica-now' ),
			'description' => __( 'State of the Publica.now plugin. Nothing here identifies a buyer; the OAuth secret is not included.', 'publica-now' ),
			'fields'      => array(
				'version'          => array(
					'label' => __( 'Plugin version', 'publica-now' ),
					'value' => PUBLICANOW_VERSION,
				),
				'api_base'         => array(
					'label' => __( 'API base', 'publica-now' ),
					'value' => $client->base(),
				),
				'sandbox'          => array(
					'label' => __( 'Sandbox', 'publica-now' ),
					'value' => $client->is_sandbox() ? __( 'Yes', 'publica-now' ) : __( 'No', 'publica-now' ),
					'debug' => $client->is_sandbox() ? 'yes' : 'no',
				),
				'connected_slug'   => array(
					'label' => __( 'Connected creator slug', 'publica-now' ),
					'value' => '' !== $slug ? $slug : __( 'Not connected', 'publica-now' ),
					'debug' => '' !== $slug ? $slug : 'none',
				),
				'creator_name'     => array(
					'label' => __( 'Connected creator name', 'publica-now' ),
					'value' => null !== $creator && ! empty( $creator['name'] ) ? (string) $creator['name'] : '—',
				),
				'website_matches'  => array(
					'label' => __( 'Verified website', 'publica-now' ),
					'value' => null !== $creator && ! empty( $creator['website_matches'] ) ? __( 'Yes', 'publica-now' ) : __( 'No', 'publica-now' ),
					'debug' => null !== $creator && ! empty( $creator['website_matches'] ) ? 'yes' : 'no',
				),
				'creator_fetched'  => array(
					'label' => __( 'Profile last checked', 'publica-now' ),
					'value' => null !== $creator && ! empty( $creator['fetched_at'] )
						? sprintf(
							/* translators: %s: human time difference. */
							__( '%s ago', 'publica-now' ),
							human_time_diff( (int) $creator['fetched_at'] )
						)
						: '—',
					'debug' => null !== $creator && ! empty( $creator['fetched_at'] ) ? gmdate( 'c', (int) $creator['fetched_at'] ) : 'never',
				),
				'cache_ttl'        => array(
					'label' => __( 'Cache TTL (seconds)', 'publica-now' ),
					'value' => (string) Cache::ttl(),
				),
				'cache_generation' => array(
					'label' => __( 'Cache generation', 'publica-now' ),
					'value' => (string) Cache::generation(),
				),
				'catalog_cached'   => array(
					'label' => __( 'Catalog cached', 'publica-now' ),
					'value' => '' !== $slug && Cache::has( 'works:' . $slug ) ? __( 'Yes (fresh)', 'publica-now' ) : ( null !== $stale_age ? __( 'Stale copy only', 'publica-now' ) : __( 'No', 'publica-now' ) ),
					'debug' => '' !== $slug && Cache::has( 'works:' . $slug ) ? 'fresh' : ( null !== $stale_age ? 'stale' : 'none' ),
				),
				'token_cached'     => array(
					'label' => __( 'Access token cached', 'publica-now' ),
					'value' => $client->has_cached_token() ? __( 'Yes', 'publica-now' ) : __( 'No', 'publica-now' ),
					'debug' => $client->has_cached_token() ? 'yes' : 'no',
				),
				'oauth_client'     => array(
					'label' => __( 'OAuth client registered', 'publica-now' ),
					'value' => null !== $oauth
						? sprintf(
							/* translators: %s: date. */
							__( 'Yes, on %s', 'publica-now' ),
							wp_date( get_option( 'date_format' ), (int) $oauth['registered_at'] )
						)
						: __( 'No (registered on first use)', 'publica-now' ),
					'debug' => null !== $oauth ? gmdate( 'c', (int) $oauth['registered_at'] ) : 'none',
				),
			),
		);

		return $info;
	}
}
