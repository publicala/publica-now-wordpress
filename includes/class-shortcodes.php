<?php
/**
 * Shortcodes: [publicanow_works] (alias [publicanow_catalog]), [publicanow_work], [publicanow_button].
 *
 * Thin adapters: shortcode_atts() whitelists the attribute names (anything
 * unknown — including the internal wrapper_attributes key — is dropped) and
 * the Renderer sanitises the values.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode registrar and handlers.
 */
final class Shortcodes {

	/**
	 * Register the shortcodes. Called from Plugin::boot().
	 *
	 * @return void
	 */
	public static function register(): void {
		Renderer::register();

		add_shortcode( 'publicanow_works', array( __CLASS__, 'works' ) );
		add_shortcode( 'publicanow_catalog', array( __CLASS__, 'works' ) );
		add_shortcode( 'publicanow_work', array( __CLASS__, 'work' ) );
		add_shortcode( 'publicanow_button', array( __CLASS__, 'button' ) );
	}

	/**
	 * [publicanow_works creator="" content_type="" free="any" ids="" exclude=""
	 *  order="newest" limit="12" columns="3" layout="grid" show_excerpt=""
	 *  show_rating="" show_author="yes" show_type="yes" button_text="" class=""
	 *  heading_level="3"]
	 *
	 * @param array|string $atts    Shortcode attributes ('' when none).
	 * @param string|null  $content Enclosed content (unused).
	 * @param string       $tag     Shortcode tag (publicanow_works or publicanow_catalog).
	 * @return string
	 */
	public static function works( $atts = array(), $content = '', $tag = 'publicanow_works' ): string {
		$atts = shortcode_atts( Renderer::catalog_defaults(), self::atts( $atts ), self::tag( $tag, 'publicanow_works' ) );

		return Renderer::instance()->catalog( $atts );
	}

	/**
	 * [publicanow_work id="" layout="card" show_excerpt="" button_text="" class="" heading_level="3"]
	 *
	 * A bare positional value works too: [publicanow_work my-book].
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Enclosed content (unused).
	 * @param string       $tag     Shortcode tag.
	 * @return string
	 */
	public static function work( $atts = array(), $content = '', $tag = 'publicanow_work' ): string {
		$raw = self::atts( $atts );

		if ( empty( $raw['id'] ) && isset( $raw[0] ) && is_scalar( $raw[0] ) ) {
			$raw['id'] = (string) $raw[0];
		}

		$atts = shortcode_atts( Renderer::work_defaults(), $raw, self::tag( $tag, 'publicanow_work' ) );

		return Renderer::instance()->work( $atts );
	}

	/**
	 * [publicanow_button work="" text="" format="auto" class=""]
	 *
	 * A bare positional value works too: [publicanow_button my-book].
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Enclosed content (unused).
	 * @param string       $tag     Shortcode tag.
	 * @return string
	 */
	public static function button( $atts = array(), $content = '', $tag = 'publicanow_button' ): string {
		$raw = self::atts( $atts );

		if ( empty( $raw['work'] ) && isset( $raw[0] ) && is_scalar( $raw[0] ) ) {
			$raw['work'] = (string) $raw[0];
		}

		$atts = shortcode_atts( Renderer::button_defaults(), $raw, self::tag( $tag, 'publicanow_button' ) );

		return Renderer::instance()->button( $atts );
	}

	/**
	 * WordPress passes '' instead of an array when a shortcode has no attributes.
	 *
	 * @param mixed $atts Raw attributes.
	 * @return array
	 */
	private static function atts( $atts ): array {
		return is_array( $atts ) ? $atts : array();
	}

	/**
	 * Tag name for the shortcode_atts_{$tag} filter.
	 *
	 * @param mixed  $tag     Tag passed by do_shortcode().
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function tag( $tag, string $fallback ): string {
		return is_string( $tag ) && '' !== $tag ? $tag : $fallback;
	}
}
