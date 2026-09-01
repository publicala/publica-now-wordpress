<?php
/**
 * Plugin singleton: hook registration, activation and deactivation.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires Team A's classes and, when present, Team B's static registrars.
 */
final class Plugin {

	/**
	 * Documentation URL shown in the plugin row.
	 */
	const DOCS_URL = 'https://publica.now/wordpress';

	/**
	 * Support URL shown in the plugin row: the WordPress.org support forum for
	 * this plugin, which is where a hosted plugin's users are expected to go
	 * (publica.now has no general support page — /support/{creator} is the
	 * tips and subscriptions page for one creator, a different thing).
	 */
	const SUPPORT_URL = 'https://wordpress.org/support/plugin/publica-now/';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Guards against booting twice.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The plugin_basename() of the main file, e.g. "publica-now/publica-now.php".
	 *
	 * @return string
	 */
	public static function basename(): string {
		return plugin_basename( PUBLICANOW_FILE );
	}

	/**
	 * Register every hook. Called once on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/*
		 * No load_plugin_textdomain(): since WordPress 4.6 translations for a
		 * WordPress.org-hosted plugin are loaded just in time from the language
		 * packs GlotPress builds, and the plugin ships no .mo files of its own.
		 */

		Settings::instance()->register();
		Rest::instance()->register();
		Site_Health::instance()->register();
		Privacy::instance()->register();

		/*
		 * Team B registrars. Each is optional so the plugin boots — and the
		 * settings screen stays reachable — even if a rendering file is
		 * missing during integration.
		 */
		foreach ( array( 'PublicaNow\\Shortcodes', 'PublicaNow\\Blocks', 'PublicaNow\\Structured_Data' ) as $class ) {
			if ( class_exists( $class ) && is_callable( array( $class, 'register' ) ) ) {
				call_user_func( array( $class, 'register' ) );
			}
		}

		add_filter( 'plugin_action_links_' . self::basename(), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );

		/**
		 * Fires once the plugin has registered all of its hooks.
		 */
		do_action( 'publicanow_loaded' );
	}

	/**
	 * "Settings" link on the Plugins screen, first in the row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		if ( current_user_can( Settings::CAPABILITY ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( Settings::instance()->page_url() ),
					esc_html__( 'Settings', 'publica-now' )
				)
			);
		}

		return $links;
	}

	/**
	 * "Docs" and "Support" links in the plugin row meta.
	 *
	 * @param array  $meta Existing meta links.
	 * @param string $file Plugin file being rendered.
	 * @return array
	 */
	public function row_meta( $meta, $file ) {
		if ( self::basename() !== $file || ! is_array( $meta ) ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::DOCS_URL ),
			esc_html__( 'Docs', 'publica-now' )
		);
		$meta[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::SUPPORT_URL ),
			esc_html__( 'Support', 'publica-now' )
		);

		return $meta;
	}

	/**
	 * Activation for the current site: seed defaults without overwriting.
	 *
	 * @return void
	 */
	public static function activate() {
		// add_option is a no-op when the option exists: a re-activation keeps the admin's choices.
		add_option( Settings::OPTION, Settings::defaults() );
		add_option( Cache::GENERATION_OPTION, 1 );
	}

	/**
	 * Deactivation for the current site: drop cached data and the token.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cache::purge();
		Api_Client::instance()->forget_token();
	}
}
