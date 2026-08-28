<?php
/**
 * Catalog, grid layout: a list of work cards.
 *
 * Override by copying to {theme}/publica-now/works-grid.php. The outer
 * wrapper (class="publicanow publicanow-catalog …", data attributes and the
 * --publicanow-columns style) is printed by the Renderer; this template owns
 * only the list. Variables arrive in $args:
 *
 * @var array $args {
 *     @type array[] $works         Normalised works (docs/PLAN.md §7).
 *     @type int     $total         Works matching the query before "limit".
 *     @type string  $source        api|cache|stale.
 *     @type string  $creator_slug  Creator whose works these are.
 *     @type string  $layout        grid.
 *     @type int     $columns       1–6.
 *     @type bool    $show_excerpt  Show description excerpts.
 *     @type bool    $show_rating   Show ratings when present.
 *     @type bool    $show_author   Show author bylines.
 *     @type bool    $show_type     Show content-type badges.
 *     @type string  $button_text   Primary button label override ('' = default).
 *     @type int     $heading_level 2–6, heading tag of card titles.
 *     @type string  $target        "_blank" or ''.
 *     @type string  $context       catalog.
 *     @type array   $atts          Sanitised attributes.
 * }
 *
 * @package PublicaNow
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope is local (included from Renderer::include_template()).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$works = isset( $args['works'] ) && is_array( $args['works'] ) ? $args['works'] : array();

if ( empty( $works ) ) {
	return;
}

$renderer = \PublicaNow\Renderer::instance();
?>
<ul class="publicanow-grid" role="list">
	<?php foreach ( $works as $work ) : ?>
		<?php
		if ( ! is_array( $work ) ) {
			continue;
		}
		?>
		<li class="publicanow-grid__item">
			<?php echo $renderer->template( 'work-card', $renderer->card_vars( $work, $args ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the card template escapes its own output. ?>
		</li>
	<?php endforeach; ?>
</ul>
