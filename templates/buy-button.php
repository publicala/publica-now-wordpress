<?php
/**
 * Single call-to-action button for a work.
 *
 * Used by the buy-button block/shortcode. The outer <span class="publicanow
 * publicanow-buy-button"> is printed by the Renderer. Override by copying to
 * {theme}/publica-now/buy-button.php. Variables arrive in $args:
 *
 * @var array $args {
 *     @type array  $work   Normalised work (docs/PLAN.md §7).
 *     @type array  $button {kind: buy|read|print, label: string, href: string, primary: bool}.
 *     @type string $target "_blank" or ''.
 *     @type string $format digital|print|auto (what the author asked for).
 * }
 *
 * @package PublicaNow
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope is local (included from Renderer::include_template()).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$button = isset( $args['button'] ) && is_array( $args['button'] ) ? $args['button'] : array();

if ( empty( $button['href'] ) || empty( $button['label'] ) ) {
	return;
}

$target = isset( $args['target'] ) ? (string) $args['target'] : '';
$kind   = isset( $button['kind'] ) && is_string( $button['kind'] ) ? $button['kind'] : 'buy';
?>
<a class="publicanow-button publicanow-button--primary publicanow-button--<?php echo esc_attr( $kind ); ?>" href="<?php echo esc_url( $button['href'] ); ?>" rel="noopener"<?php echo '' !== $target ? ' target="' . esc_attr( $target ) . '"' : ''; ?>><?php echo esc_html( $button['label'] ); ?></a>
