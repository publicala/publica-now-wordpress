<?php
/**
 * Settings → Publica.now: connect/disconnect, display defaults, the "use it"
 * cheatsheet, the on-ramp for creators not yet on publica.now, and the
 * one-time connect notice.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen plus the shared connect/disconnect/refresh operations used
 * by both the REST routes and the no-JS admin-post fallbacks.
 */
final class Settings {

	/**
	 * Main settings option (autoload yes).
	 */
	const OPTION = 'publicanow_settings';

	/**
	 * Connected creator snapshot (autoload yes).
	 */
	const CREATOR_OPTION = 'publicanow_creator';

	/**
	 * Activation timestamp option.
	 */
	const ACTIVATED_OPTION = 'publicanow_activated_at';

	/**
	 * Per-user meta set when the connect notice is dismissed.
	 */
	const NOTICE_META = 'publicanow_notice_dismissed';

	/**
	 * Settings page slug (options-general.php?page=publica-now).
	 */
	const PAGE = 'publica-now';

	/**
	 * Settings API option group.
	 */
	const GROUP = 'publicanow_settings_group';

	/**
	 * Capability for every admin action.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Script/style handle.
	 */
	const HANDLE = 'publicanow-admin';

	/**
	 * Singleton instance.
	 *
	 * @var Settings|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Settings
	 */
	public static function instance(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Data access (static so Team B can read defaults without an instance).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Default values for every publicanow_settings key.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'creator_slug'    => '',
			'open_in_new_tab' => false,
			'cache_ttl'       => Cache::DEFAULT_TTL,
			'default_columns' => 3,
			'default_layout'  => 'grid',
			'show_excerpt'    => true,
			'show_rating'     => true,
			'button_text'     => '',
		);
	}

	/**
	 * All settings, merged over defaults and type-normalised.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return self::normalise( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Merge values into the stored settings.
	 *
	 * @param array $values Partial settings.
	 * @return void
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, self::normalise( array_merge( self::all(), $values ) ) );
	}

	/**
	 * The stored creator snapshot, or null when not connected.
	 *
	 * @return array|null
	 */
	public static function creator() {
		$stored = get_option( self::CREATOR_OPTION );

		return is_array( $stored ) && ! empty( $stored['slug'] ) ? $stored : null;
	}

	/**
	 * Whether a creator is connected.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return '' !== Catalog::instance()->connected_slug();
	}

	/**
	 * Coerce every key to its documented type; unknown keys are dropped.
	 *
	 * @param array $input Raw values.
	 * @return array
	 */
	private static function normalise( array $input ): array {
		$defaults = self::defaults();
		$out      = $defaults;

		$slug                = isset( $input['creator_slug'] ) ? Catalog::normalise_slug( (string) $input['creator_slug'] ) : '';
		$out['creator_slug'] = $slug;

		foreach ( array( 'open_in_new_tab', 'show_excerpt', 'show_rating' ) as $flag ) {
			$out[ $flag ] = array_key_exists( $flag, $input ) ? rest_sanitize_boolean( $input[ $flag ] ) : $defaults[ $flag ];
		}

		$ttl              = isset( $input['cache_ttl'] ) ? (int) $input['cache_ttl'] : $defaults['cache_ttl'];
		$out['cache_ttl'] = min( DAY_IN_SECONDS, max( 60, $ttl ) );

		$columns                = isset( $input['default_columns'] ) ? (int) $input['default_columns'] : $defaults['default_columns'];
		$out['default_columns'] = min( 6, max( 1, $columns ) );

		$layout                = isset( $input['default_layout'] ) ? (string) $input['default_layout'] : $defaults['default_layout'];
		$out['default_layout'] = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';

		$out['button_text'] = isset( $input['button_text'] ) ? sanitize_text_field( (string) $input['button_text'] ) : '';

		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Connect / disconnect / refresh (shared by REST and admin-post).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Connect to a creator: validate against the API, store the snapshot,
	 * purge caches from a previous creator.
	 *
	 * @param string $input Slug or profile URL as pasted by the admin.
	 * @return array|WP_Error {creator: array, website_matches: bool}
	 */
	public function connect( string $input ) {
		$slug = Catalog::normalise_slug( $input );

		if ( '' === $slug ) {
			return new WP_Error(
				'publicanow_invalid_slug',
				__( 'Paste your publica.now profile URL (https://publica.now/creators/your-name) or just your creator slug.', 'publica-now' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * The ONLY place (with refresh()) that may create OAuth credentials at
		 * publica.now: an administrator has just clicked Connect, with a nonce
		 * checked. Read paths never register — see Api_Client::authorize().
		 */
		$authorized = Api_Client::instance()->authorize();

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$creator = Catalog::instance()->creator( $slug, true );

		if ( is_wp_error( $creator ) ) {
			if ( 'publicanow_not_found' === $creator->get_error_code() ) {
				return new WP_Error(
					'publicanow_not_found',
					sprintf(
						/* translators: %s: creator slug. */
						__( 'No publica.now creator is published under “%s”. Check the URL of your public profile.', 'publica-now' ),
						$slug
					),
					array( 'status' => 404 )
				);
			}

			return $creator;
		}

		$previous = Catalog::instance()->connected_slug();
		$matches  = self::website_matches( $creator['website'] );

		self::update( array( 'creator_slug' => $creator['slug'] ) );

		update_option(
			self::CREATOR_OPTION,
			array_merge(
				$creator,
				array(
					'fetched_at'      => time(),
					'website_matches' => $matches,
				)
			)
		);

		if ( '' !== $previous && $previous !== $creator['slug'] ) {
			// Another creator's catalog must not linger in the cache.
			Cache::purge();
		}

		/**
		 * Fires after a creator has been connected.
		 *
		 * @param array $creator Normalised creator.
		 */
		do_action( 'publicanow_connected', $creator );

		return array(
			'creator'         => $creator,
			'website_matches' => $matches,
		);
	}

	/**
	 * Disconnect: revoke the access token at publica.now, drop the OAuth
	 * client, forget the creator and purge every cached response.
	 *
	 * This is what readme.txt promises under "External Services", and after
	 * this call the site holds nothing from publica.now. Reconnecting registers
	 * a fresh client (throttled at 10 per hour per IP, which is ample for a
	 * human clicking Connect).
	 *
	 * @return string The slug that was connected ('' if none).
	 */
	public function disconnect(): string {
		$previous = Catalog::instance()->connected_slug();

		Api_Client::instance()->revoke();
		Api_Client::instance()->forget_client();

		self::update( array( 'creator_slug' => '' ) );
		delete_option( self::CREATOR_OPTION );
		Cache::purge();

		/**
		 * Fires after the creator has been disconnected.
		 *
		 * @param string $previous The slug that was connected.
		 */
		do_action( 'publicanow_disconnected', $previous );

		return $previous;
	}

	/**
	 * Refresh: re-read the profile from publica.now and, only if that works,
	 * purge every cached response so the next page view fetches fresh data.
	 *
	 * The order matters. Cache::purge() deletes the 7-day outage copy, so
	 * purging first would mean that clicking "Refresh catalog" during an outage
	 * — exactly when an admin reaches for it — takes the catalog off the site
	 * until publica.now comes back. Nothing is purged unless the API answered.
	 *
	 * @return array|WP_Error The refreshed creator, or the error (caches untouched).
	 */
	public function refresh() {
		$slug = Catalog::instance()->connected_slug();

		if ( '' === $slug ) {
			return new WP_Error(
				'publicanow_not_connected',
				__( 'No publica.now account is connected.', 'publica-now' ),
				array( 'status' => 400 )
			);
		}

		// Admin action behind manage_options + a nonce: may (re-)register.
		$authorized = Api_Client::instance()->authorize();

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		/*
		 * Deliberately not Catalog::creator(): that method falls back to the
		 * stale copy on failure, which would report success while the API is
		 * down and let the purge below run.
		 */
		$response = Api_Client::instance()->get( '/api/v1/public/creators/' . rawurlencode( $slug ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw     = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
		$creator = Catalog::instance()->finish_creator( Catalog::instance()->normalise_creator( $raw ) );

		Cache::purge();
		Cache::set( 'creator:' . $slug, $creator );

		update_option(
			self::CREATOR_OPTION,
			array_merge(
				$creator,
				array(
					'fetched_at'      => time(),
					'website_matches' => self::website_matches( $creator['website'] ),
				)
			)
		);

		return $creator;
	}

	/**
	 * Whether the creator's publica.now website matches this site's host.
	 * Informational only — it is never a gate.
	 *
	 * @param string|null $website Creator website URL.
	 * @return bool
	 */
	public static function website_matches( $website ): bool {
		if ( ! is_string( $website ) || '' === $website ) {
			return false;
		}

		$theirs = wp_parse_url( $website, PHP_URL_HOST );
		$ours   = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $theirs ) || ! is_string( $ours ) ) {
			return false;
		}

		$strip = static function ( $host ) {
			return preg_replace( '/^www\./i', '', strtolower( $host ) );
		};

		return $strip( $theirs ) === $strip( $ours );
	}

	/**
	 * URL of the settings page.
	 *
	 * @return string
	 */
	public function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * The creator sign-up URL with attribution.
	 *
	 * @return string
	 */
	public static function signup_url(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return add_query_arg(
			array(
				'utm_source'   => is_string( $host ) ? $host : 'wordpress',
				'utm_medium'   => 'wordpress_plugin',
				'utm_campaign' => 'publica-now-plugin',
			),
			Api_Client::instance()->base() . '/access/creator'
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Hooks.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register() {
		// register_setting on init (not admin_init) so update_option() from REST is sanitised too.
		add_action( 'init', array( $this, 'register_setting' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'connect_notice' ) );

		add_action( 'admin_post_publicanow_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_publicanow_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_publicanow_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_publicanow_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize callback for the option. Also runs for update_option().
	 *
	 * The display-defaults form does not carry creator_slug, so an absent
	 * key means "keep the connected creator", not "disconnect".
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		if ( ! array_key_exists( 'creator_slug', $input ) ) {
			$input['creator_slug'] = (string) self::get( 'creator_slug', '' );
		}

		// Browsers omit unchecked checkboxes from the POST body: absent means off.
		foreach ( array( 'open_in_new_tab', 'show_excerpt', 'show_rating' ) as $flag ) {
			if ( ! array_key_exists( $flag, $input ) ) {
				$input[ $flag ] = false;
			}
		}

		return self::normalise( $input );
	}

	/**
	 * Settings → Publica.now.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Publica.now', 'publica-now' ),
			__( 'Publica.now', 'publica-now' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Sections and fields for the display defaults.
	 *
	 * @return void
	 */
	public function register_fields() {
		add_settings_section(
			'publicanow_display',
			__( 'Display defaults', 'publica-now' ),
			array( $this, 'render_display_intro' ),
			self::PAGE
		);

		$fields = array(
			'default_layout'  => __( 'Default layout', 'publica-now' ),
			'default_columns' => __( 'Default columns', 'publica-now' ),
			'show_excerpt'    => __( 'Excerpts', 'publica-now' ),
			'show_rating'     => __( 'Ratings', 'publica-now' ),
			'button_text'     => __( 'Button text', 'publica-now' ),
			'open_in_new_tab' => __( 'Links', 'publica-now' ),
			'cache_ttl'       => __( 'Cache', 'publica-now' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'publicanow_' . $key,
				$label,
				array( $this, 'render_field' ),
				self::PAGE,
				'publicanow_display',
				array(
					'key'       => $key,
					'label_for' => 'publicanow_' . $key,
				)
			);
		}
	}

	/**
	 * Intro text of the display section.
	 *
	 * @return void
	 */
	public function render_display_intro() {
		echo '<p>' . esc_html__( 'Defaults for every catalog, work and button you place. Each block and shortcode can override them.', 'publica-now' ) . '</p>';
	}

	/**
	 * Render one display-defaults field.
	 *
	 * @param array $args Field args (key).
	 * @return void
	 */
	public function render_field( $args ) {
		$key      = isset( $args['key'] ) ? (string) $args['key'] : '';
		$settings = self::all();
		$id       = 'publicanow_' . $key;
		$name     = self::OPTION . '[' . $key . ']';

		if ( ! array_key_exists( $key, $settings ) ) {
			return;
		}

		$value = $settings[ $key ];

		switch ( $key ) {
			case 'default_layout':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( array(
					'grid' => __( 'Grid', 'publica-now' ),
					'list' => __( 'List', 'publica-now' ),
				) as $option => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;

			case 'default_columns':
				printf(
					'<input type="number" id="%s" name="%s" value="%d" min="1" max="6" step="1" class="small-text" /> <p class="description">%s</p>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value,
					esc_html__( 'Columns in the grid layout on wide screens (1–6).', 'publica-now' )
				);
				break;

			case 'cache_ttl':
				printf(
					'<input type="number" id="%s" name="%s" value="%d" min="60" max="%d" step="60" class="regular-text" /> <p class="description">%s</p>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value,
					(int) DAY_IN_SECONDS,
					esc_html__( 'Seconds to keep catalog data before asking publica.now again. Default 900 (15 minutes). A copy is always kept for 7 days in case publica.now is unreachable.', 'publica-now' )
				);
				break;

			case 'button_text':
				printf(
					'<input type="text" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" /> <p class="description">%s</p>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr__( 'Buy', 'publica-now' ),
					esc_html__( 'Replaces the “Buy” label. “Read free” and “Order paperback” stay as they are.', 'publica-now' )
				);
				break;

			case 'show_excerpt':
				$this->checkbox( $id, $name, (bool) $value, __( 'Show the excerpt on cards', 'publica-now' ) );
				break;

			case 'show_rating':
				$this->checkbox( $id, $name, (bool) $value, __( 'Show the rating when a work has one', 'publica-now' ) );
				break;

			case 'open_in_new_tab':
				$this->checkbox( $id, $name, (bool) $value, __( 'Open publica.now in a new tab', 'publica-now' ) );
				break;
		}
	}

	/**
	 * Print a checkbox with its label.
	 *
	 * @param string $id      Element id.
	 * @param string $name    Field name.
	 * @param bool   $checked Checked state.
	 * @param string $label   Label text.
	 * @return void
	 */
	private function checkbox( string $id, string $name, bool $checked, string $label ) {
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
			esc_attr( $id ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Enqueue admin assets on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			PUBLICANOW_URL . 'assets/css/admin.css',
			array(),
			PUBLICANOW_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			PUBLICANOW_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			PUBLICANOW_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'publica-now' );

		wp_localize_script(
			self::HANDLE,
			'publicanowAdmin',
			array(
				'root'      => esc_url_raw( rest_url() ),
				'namespace' => Rest::NAMESPACE,
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'connected' => self::is_connected(),
				'slug'      => Catalog::instance()->connected_slug(),
			)
		);
	}

	/**
	 * The one-time notice after activation, shown until connected or dismissed.
	 *
	 * @return void
	 */
	public function connect_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || self::is_connected() ) {
			return;
		}

		if ( ! get_option( self::ACTIVATED_OPTION ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::NOTICE_META, true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && 'settings_page_' . self::PAGE === $screen->id ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'publicanow_dismiss_notice',
					'redirect' => rawurlencode( self::current_admin_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			'publicanow_dismiss_notice'
		);

		echo '<div class="notice notice-info publicanow-notice"><p>';
		echo '<strong>' . esc_html__( 'Publica.now is ready.', 'publica-now' ) . '</strong> ';
		echo esc_html__( 'Connect your publica.now account to start showing your books on this site.', 'publica-now' );
		echo '</p><p>';
		printf(
			'<a class="button button-primary" href="%s">%s</a> ',
			esc_url( $this->page_url() ),
			esc_html__( 'Connect now', 'publica-now' )
		);
		printf(
			'<a class="button-link" href="%s">%s</a>',
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'publica-now' )
		);
		echo '</p></div>';
	}

	/**
	 * Current admin URL for post-dismiss redirects (path + query only).
	 *
	 * @return string
	 */
	private static function current_admin_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return '' === $uri ? admin_url() : $uri;
	}

	/*
	 * ---------------------------------------------------------------------
	 * admin-post handlers (no-JS fallbacks; the JS path uses REST).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Connect (form post).
	 *
	 * @return void
	 */
	public function handle_connect() {
		$this->authorize( 'publicanow_connect' );

		$input  = isset( $_POST['publicanow_creator'] ) ? sanitize_text_field( wp_unslash( $_POST['publicanow_creator'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$result = $this->connect( $input );

		if ( is_wp_error( $result ) ) {
			$this->finish( 'error', $result->get_error_message() );
		}

		$this->finish(
			'success',
			sprintf(
				/* translators: %s: creator name. */
				__( 'Connected to %s.', 'publica-now' ),
				$result['creator']['name']
			)
		);
	}

	/**
	 * Disconnect (form post).
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		$this->authorize( 'publicanow_disconnect' );
		$this->disconnect();
		$this->finish( 'success', __( 'Disconnected. The API credentials were revoked and deleted; your display settings were kept.', 'publica-now' ) );
	}

	/**
	 * Refresh catalog (form post).
	 *
	 * @return void
	 */
	public function handle_refresh() {
		$this->authorize( 'publicanow_refresh' );

		$result = $this->refresh();

		if ( is_wp_error( $result ) ) {
			$this->finish(
				'warning',
				sprintf(
					/* translators: %s: error message. */
					__( 'Nothing was cleared: publica.now could not be reached (%s). Your saved copy of the catalog is still being shown.', 'publica-now' ),
					$result->get_error_message()
				)
			);
		}

		$this->finish( 'success', __( 'Catalog refreshed.', 'publica-now' ) );
	}

	/**
	 * Dismiss the connect notice for the current user.
	 *
	 * @return void
	 */
	public function handle_dismiss_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'publica-now' ), 403 );
		}

		check_admin_referer( 'publicanow_dismiss_notice' );

		update_user_meta( get_current_user_id(), self::NOTICE_META, time() );

		$redirect = isset( $_GET['redirect'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['redirect'] ) ) ) : '';

		// Only a site-relative path is accepted; wp_safe_redirect() rejects foreign hosts anyway.
		if ( '' === $redirect || '/' !== substr( $redirect, 0, 1 ) ) {
			$redirect = admin_url();
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Capability + nonce check for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( string $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'publica-now' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Queue a message through the Settings API transient (core prints it on
	 * our page via options-head.php) and redirect back.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 * @return void
	 */
	private function finish( string $type, string $message ) {
		add_settings_error( 'publicanow', 'publicanow_' . $type, $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', $this->page_url() ) );
		exit;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Page.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'publica-now' ), 403 );
		}

		$creator = self::creator();
		$slug    = Catalog::instance()->connected_slug();
		$client  = Api_Client::instance();

		echo '<div class="wrap publicanow-admin">';
		echo '<h1>' . esc_html__( 'Publica.now', 'publica-now' ) . '</h1>';

		if ( $client->is_sandbox() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Sandbox mode is on: the plugin reads publica.now’s fixture catalog, not real data.', 'publica-now' ) . '</p></div>';
		}

		$this->render_connect_section( $creator, $slug );
		$this->render_display_section();
		$this->render_cheatsheet_section( $slug );
		$this->render_onramp_section();

		echo '</div>';
	}

	/**
	 * Section 1: connect / connected card.
	 *
	 * @param array|null $creator Stored creator snapshot.
	 * @param string     $slug    Connected slug.
	 * @return void
	 */
	private function render_connect_section( $creator, string $slug ) {
		echo '<section class="publicanow-section publicanow-connect" id="publicanow-connect">';
		echo '<h2>' . esc_html__( 'Your publica.now account', 'publica-now' ) . '</h2>';

		if ( '' === $slug || null === $creator ) {
			echo '<p>' . esc_html__( 'Paste the URL of your public profile on publica.now. The plugin reads your published works from there — nothing is written to your account.', 'publica-now' ) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="publicanow-connect-form" data-publicanow-connect>';
			echo '<input type="hidden" name="action" value="publicanow_connect" />';
			wp_nonce_field( 'publicanow_connect' );
			echo '<label class="screen-reader-text" for="publicanow_creator">' . esc_html__( 'Profile URL or creator slug', 'publica-now' ) . '</label>';
			printf(
				'<input type="text" id="publicanow_creator" name="publicanow_creator" class="regular-text" placeholder="%s" required autocomplete="off" spellcheck="false" /> ',
				esc_attr( 'https://publica.now/creators/your-name' )
			);
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Connect', 'publica-now' ) . '</button>';
			echo '<p class="publicanow-status" data-publicanow-status role="status" aria-live="polite"></p>';
			echo '</form>';
			$this->render_service_terms();
			echo '</section>';

			return;
		}

		$name   = isset( $creator['name'] ) ? (string) $creator['name'] : $slug;
		$count  = isset( $creator['works_count'] ) ? (int) $creator['works_count'] : 0;
		$url    = isset( $creator['url'] ) ? (string) $creator['url'] : Api_Client::instance()->base() . '/creators/' . rawurlencode( $slug );
		$avatar = isset( $creator['avatar_url'] ) && is_string( $creator['avatar_url'] ) ? $creator['avatar_url'] : '';

		echo '<div class="publicanow-card">';

		if ( '' !== $avatar ) {
			printf( '<img class="publicanow-avatar" src="%s" alt="" width="64" height="64" loading="lazy" />', esc_url( $avatar ) );
		} else {
			printf( '<span class="publicanow-avatar publicanow-avatar-fallback" aria-hidden="true">%s</span>', esc_html( mb_substr( $name, 0, 1 ) ) );
		}

		echo '<div class="publicanow-card-body">';
		echo '<p class="publicanow-card-name">' . esc_html( $name );

		if ( ! empty( $creator['website_matches'] ) ) {
			echo ' <span class="publicanow-badge publicanow-badge-verified">' . esc_html__( 'Verified website', 'publica-now' ) . '</span>';
		}

		echo '</p>';

		printf(
			'<p class="publicanow-card-meta">%s · <a href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %s: number of works. */
					_n( '%s published work', '%s published works', $count, 'publica-now' ),
					number_format_i18n( $count )
				)
			),
			esc_url( $url ),
			esc_html__( 'View profile on publica.now', 'publica-now' )
		);

		if ( empty( $creator['website_matches'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: this site's home URL. */
						__( 'Add %s as your website on publica.now to show the “Verified website” badge here.', 'publica-now' ),
						home_url()
					)
				)
			);
		}

		echo '<div class="publicanow-card-actions">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="publicanow-inline-form">';
		echo '<input type="hidden" name="action" value="publicanow_refresh" />';
		wp_nonce_field( 'publicanow_refresh' );
		echo '<button type="submit" class="button" data-publicanow-action="purge">' . esc_html__( 'Refresh catalog', 'publica-now' ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="publicanow-inline-form">';
		echo '<input type="hidden" name="action" value="publicanow_disconnect" />';
		wp_nonce_field( 'publicanow_disconnect' );
		printf(
			'<button type="submit" class="button button-link-delete" data-publicanow-action="disconnect" data-publicanow-confirm="%s">%s</button>',
			esc_attr__( 'Disconnect this publica.now account? The API credentials are revoked and deleted, and your catalog blocks stop rendering until you connect again.', 'publica-now' ),
			esc_html__( 'Disconnect', 'publica-now' )
		);
		echo '</form>';

		echo '</div>';
		echo '<p class="publicanow-status" data-publicanow-status role="status" aria-live="polite"></p>';
		echo '</div></div>';

		if ( ! empty( $creator['fetched_at'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human time difference. */
						__( 'Profile last checked %s ago.', 'publica-now' ),
						human_time_diff( (int) $creator['fetched_at'] )
					)
				)
			);
		}

		$this->render_service_terms();

		echo '</section>';
	}

	/**
	 * What connecting means, plus publica.now's terms and privacy policy.
	 *
	 * WordPress.org expects a plugin that depends on an external service to say
	 * so on the screen where the connection is made, not only in readme.txt.
	 *
	 * @return void
	 */
	private function render_service_terms() {
		$base = Api_Client::instance()->base();

		printf(
			'<p class="description publicanow-service-terms">%1$s <a href="%2$s" target="_blank" rel="noopener">%3$s</a> · <a href="%4$s" target="_blank" rel="noopener">%5$s</a></p>',
			esc_html__( 'Connecting registers a read-only API client with publica.now and stores its credentials on this site. Disconnecting revokes them.', 'publica-now' ),
			esc_url( $base . '/terms' ),
			esc_html__( 'publica.now terms of service', 'publica-now' ),
			esc_url( $base . '/privacy' ),
			esc_html__( 'publica.now privacy policy', 'publica-now' )
		);
	}

	/**
	 * Section 2: display defaults (Settings API form).
	 *
	 * @return void
	 */
	private function render_display_section() {
		echo '<section class="publicanow-section" id="publicanow-display">';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button( __( 'Save display defaults', 'publica-now' ) );
		echo '</form>';
		echo '</section>';
	}

	/**
	 * Section 3: cheatsheet with copy buttons.
	 *
	 * @param string $slug Connected slug ('' when not connected).
	 * @return void
	 */
	private function render_cheatsheet_section( string $slug ) {
		$creator_attr = '' !== $slug ? ' creator="' . $slug . '"' : '';

		$entries = array(
			array(
				'title'       => __( 'Catalog', 'publica-now' ),
				'description' => __( 'A grid or list of your works. Block: “Publica.now Catalog”.', 'publica-now' ),
				'code'        => '[publicanow_works' . $creator_attr . ' limit="12" columns="3" layout="grid" order="newest"]',
				'attributes'  => 'creator, content_type, free (yes|no|any), ids, exclude, order (newest|oldest|title|price_asc|price_desc), limit (0 = all), columns (1–6), layout (grid|list), show_excerpt, show_rating, show_author, show_type, button_text, class',
			),
			array(
				'title'       => __( 'Single work', 'publica-now' ),
				'description' => __( 'One work as a card or an inline row. Block: “Publica.now Work”.', 'publica-now' ),
				'code'        => '[publicanow_work id="your-work-slug" layout="card"]',
				'attributes'  => 'id (id or slug, required), layout (card|inline), show_excerpt, button_text, class',
			),
			array(
				'title'       => __( 'Buy button', 'publica-now' ),
				'description' => __( 'Just the button, anywhere in your text. Block: “Publica.now Buy Button”.', 'publica-now' ),
				'code'        => '[publicanow_button work="your-work-slug" format="auto"]',
				'attributes'  => 'work (id or slug, required), text, format (digital|print|auto), class',
			),
		);

		echo '<section class="publicanow-section" id="publicanow-use">';
		echo '<h2>' . esc_html__( 'Use it', 'publica-now' ) . '</h2>';
		echo '<p>' . esc_html__( 'In the block editor, search for “Publica.now” to insert a Catalog, a Work or a Buy Button. Anywhere else, use the shortcodes below.', 'publica-now' ) . '</p>';

		if ( '' === $slug ) {
			echo '<p class="description">' . esc_html__( 'Connect your account above and the examples will be filled in with your creator slug.', 'publica-now' ) . '</p>';
		}

		echo '<div class="publicanow-cheatsheet">';

		foreach ( $entries as $index => $entry ) {
			$code_id = 'publicanow-snippet-' . (int) $index;

			echo '<div class="publicanow-snippet">';
			echo '<h3>' . esc_html( $entry['title'] ) . '</h3>';
			echo '<p>' . esc_html( $entry['description'] ) . '</p>';
			echo '<div class="publicanow-snippet-code">';
			printf( '<code id="%s">%s</code>', esc_attr( $code_id ), esc_html( $entry['code'] ) );
			printf(
				'<button type="button" class="button button-small" data-publicanow-copy="%s">%s</button>',
				esc_attr( $code_id ),
				esc_html__( 'Copy', 'publica-now' )
			);
			echo '</div>';
			printf(
				'<p class="description"><strong>%s</strong> %s</p>',
				esc_html__( 'Attributes:', 'publica-now' ),
				esc_html( $entry['attributes'] )
			);
			echo '</div>';
		}

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Section 4: for creators who are not on publica.now yet.
	 *
	 * @return void
	 */
	private function render_onramp_section() {
		echo '<section class="publicanow-section publicanow-onramp" id="publicanow-new">';
		echo '<h2>' . esc_html__( 'New to Publica.now?', 'publica-now' ) . '</h2>';
		echo '<p>' . esc_html__( 'Publica.now is where independent creators publish and sell ebooks (PDF and EPUB), audiobooks, music, video, courses and print-on-demand books directly to their readers. You keep control of your work and pay 20% + $0.30 per sale, with no monthly fee. Buyers pay on publica.now, which handles checkout, delivery and printing, and read or listen in the built-in reader on any device. This plugin then brings that catalog back onto your own WordPress site.', 'publica-now' ) . '</p>';
		printf(
			'<p><a class="button button-primary" href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_url( self::signup_url() ),
			esc_html__( 'Start selling on Publica.now', 'publica-now' )
		);
		echo '</section>';
	}
}
