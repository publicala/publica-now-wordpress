<?php
/**
 * Plugin Name:       Publica.now – Sell Ebooks, Audiobooks, Video & Print Books
 * Plugin URI:        https://publica.now/wordpress
 * Description:       Show your publica.now catalog on your site and sell PDF, EPUB, audio, video and print-on-demand books. Checkout happens on publica.now.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Publica.la
 * Author URI:        https://publica.now
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       publica-now
 * Domain Path:       /languages
 *
 * @package PublicaNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Constants are defined before anything else so that every class, the
 * activation callbacks and uninstall-time helpers can rely on them.
 */
define( 'PUBLICANOW_VERSION', '1.0.0' );
define( 'PUBLICANOW_FILE', __FILE__ );
define( 'PUBLICANOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'PUBLICANOW_URL', plugin_dir_url( __FILE__ ) );
define( 'PUBLICANOW_MIN_PHP', '7.4' );
define( 'PUBLICANOW_MIN_WP', '6.2' );

if ( ! defined( 'PUBLICANOW_API_BASE' ) ) {
	/*
	 * The only host the plugin ever talks to. It is a constant (overridable
	 * from wp-config.php for staging) and never a setting: a stored, editable
	 * URL would turn the plugin into an open proxy for whoever edits it.
	 */
	define( 'PUBLICANOW_API_BASE', 'https://publica.now' );
}

/**
 * Tiny PSR-4 style autoloader: PublicaNow\Foo_Bar → includes/class-foo-bar.php.
 *
 * Kept deliberately minimal (no Composer) so the WordPress.org zip has no
 * build step and no vendor directory.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function publicanow_autoload( $class_name ) {
	$prefix = 'PublicaNow\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$file     = PUBLICANOW_PATH . 'includes/class-' . str_replace( '_', '-', strtolower( $relative ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'publicanow_autoload' );

/**
 * Whether the environment satisfies the minimum PHP and WordPress versions.
 *
 * @return bool
 */
function publicanow_environment_ok() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, PUBLICANOW_MIN_PHP, '<' ) ) {
		return false;
	}

	if ( isset( $wp_version ) && version_compare( $wp_version, PUBLICANOW_MIN_WP, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Admin notice shown instead of a fatal error when the environment is too old.
 *
 * @return void
 */
function publicanow_environment_notice() {
	global $wp_version;

	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: required WordPress version, 3: running PHP version, 4: running WordPress version. */
				__( 'Publica.now requires PHP %1$s and WordPress %2$s or newer. This site runs PHP %3$s and WordPress %4$s, so the plugin stays inactive.', 'publica-now' ),
				PUBLICANOW_MIN_PHP,
				PUBLICANOW_MIN_WP,
				PHP_VERSION,
				isset( $wp_version ) ? $wp_version : '?'
			)
		)
	);
}

/**
 * Activation: remember when we were activated (drives the one-time connect
 * notice) and seed default settings without overwriting existing ones.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 * @return void
 */
function publicanow_activate( $network_wide = false ) {
	if ( ! publicanow_environment_ok() ) {
		return;
	}

	if ( $network_wide && is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			\PublicaNow\Plugin::activate();
			restore_current_blog();
		}
		return;
	}

	\PublicaNow\Plugin::activate();
}
register_activation_hook( __FILE__, 'publicanow_activate' );

/**
 * Deactivation: drop cached API data and the access token. Settings and the
 * OAuth client survive so re-activating does not re-register a client.
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 * @return void
 */
function publicanow_deactivate( $network_wide = false ) {
	if ( ! publicanow_environment_ok() ) {
		return;
	}

	if ( $network_wide && is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			\PublicaNow\Plugin::deactivate();
			restore_current_blog();
		}
		return;
	}

	\PublicaNow\Plugin::deactivate();
}
register_deactivation_hook( __FILE__, 'publicanow_deactivate' );

/**
 * Boot the plugin once every plugin is loaded, so Team B registrars and
 * third-party filters (publicanow_api_base, publicanow_sandbox) can exist.
 *
 * @return void
 */
function publicanow_boot() {
	if ( ! publicanow_environment_ok() ) {
		add_action( 'admin_notices', 'publicanow_environment_notice' );
		return;
	}

	\PublicaNow\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'publicanow_boot' );
