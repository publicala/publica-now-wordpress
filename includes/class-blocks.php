<?php
/**
 * Block registration: publica-now/catalog, publica-now/work, publica-now/buy-button.
 *
 * Each block is fully described by its block.json (attributes, supports,
 * "render": file:./render.php, "editorScript": file:./index.js). WordPress
 * reads blocks/{name}/index.asset.php for the script dependencies, so there
 * is no build step and nothing to bundle for WordPress.org.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the three dynamic blocks from their block.json files.
 */
final class Blocks {

	/**
	 * Block directory names under blocks/.
	 */
	const NAMES = array( 'catalog', 'work', 'buy-button' );

	/**
	 * Hook block registration. Called from Plugin::boot().
	 *
	 * @return void
	 */
	public static function register(): void {
		// The shared stylesheet handle must exist before block.json's "style": "publica-now" is resolved.
		Renderer::register();

		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		}
	}

	/**
	 * Call register_block_type() for every block directory that has a block.json.
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		foreach ( self::NAMES as $name ) {
			$dir = self::path() . 'blocks/' . $name;

			if ( ! is_readable( $dir . '/block.json' ) ) {
				continue;
			}

			register_block_type( $dir );
		}
	}

	/**
	 * Plugin directory path with trailing slash.
	 *
	 * @return string
	 */
	private static function path(): string {
		return trailingslashit( defined( 'PUBLICANOW_PATH' ) ? PUBLICANOW_PATH : dirname( __DIR__ ) );
	}
}
