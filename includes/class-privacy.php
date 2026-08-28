<?php
/**
 * Suggested privacy-policy text (Settings → Privacy → Policy Guide).
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's paragraph in the privacy policy guide. The text is
 * honest about the little that leaves the site: nothing about buyers ever
 * touches WordPress.
 */
final class Privacy {

	/**
	 * Singleton instance.
	 *
	 * @var Privacy|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Privacy
	 */
	public static function instance(): Privacy {
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
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Add the suggested text.
	 *
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? $host : '';

		$paragraphs = array(
			__( 'This site uses the Publica.now plugin to display a catalog of digital and printed works that are sold on publica.now, a service operated by Publica.la. The plugin does not process payments, deliver files or create customer accounts on this site, and it stores no data about buyers or visitors.', 'publica-now' ),
			sprintf(
				/* translators: %s: this site's host name. */
				__( 'When you click a “Buy”, “Read free” or “Order paperback” button you leave this site for publica.now. Those links carry this site’s host name (%s) as a referral parameter so that publica.now can attribute the sale to this website. Any purchase, account or reading activity from that point on is governed by the publica.now privacy policy at https://publica.now/privacy.', 'publica-now' ),
				$host
			),
			__( 'Cover images shown by the plugin are loaded directly from publica.now, so your browser sends publica.now the usual request information (such as your IP address and browser type) when a page with a catalog is displayed. The plugin sets no cookies of its own.', 'publica-now' ),
		);

		$content = '';
		foreach ( $paragraphs as $paragraph ) {
			$content .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		wp_add_privacy_policy_content( 'Publica.now', wp_kses_post( $content ) );
	}
}
