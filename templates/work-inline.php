<?php
/**
 * Work, inline layout: a compact row — small cover, title, meta, buttons.
 *
 * Used by the single-work block/shortcode with layout="inline". Override by
 * copying to {theme}/publica-now/work-inline.php. Variables arrive in $args:
 *
 * @var array $args {
 *     @type array  $work          Normalised work (docs/PLAN.md §7).
 *     @type array  $buttons       Links::buttons_for(): [{kind, label, href, primary}].
 *     @type string $read_url      Attributed link to the work page on publica.now.
 *     @type string $target        "_blank" or ''.
 *     @type bool   $show_excerpt  Show a short excerpt under the meta line.
 *     @type bool   $show_rating   Show the rating when present.
 *     @type bool   $show_author   Show the author.
 *     @type bool   $show_type     Show the content-type badge.
 *     @type int    $heading_level 2–6, heading tag of the title.
 *     @type string $hue_class     CSS class selecting the fallback cover hue.
 *     @type string $context       "work".
 * }
 *
 * @package PublicaNow
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope is local (included from Renderer::include_template()).

use PublicaNow\Formatting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$work = isset( $args['work'] ) && is_array( $args['work'] ) ? $args['work'] : array();

if ( empty( $work ) ) {
	return;
}

$buttons      = isset( $args['buttons'] ) && is_array( $args['buttons'] ) ? $args['buttons'] : array();
$read_url     = isset( $args['read_url'] ) ? (string) $args['read_url'] : '';
$target       = isset( $args['target'] ) ? (string) $args['target'] : '';
$show_excerpt = ! empty( $args['show_excerpt'] );
$show_rating  = ! isset( $args['show_rating'] ) || ! empty( $args['show_rating'] );
$show_author  = ! isset( $args['show_author'] ) || ! empty( $args['show_author'] );
$show_type    = ! isset( $args['show_type'] ) || ! empty( $args['show_type'] );
$hue_class    = isset( $args['hue_class'] ) ? (string) $args['hue_class'] : 'publicanow-hue-0';
$heading      = isset( $args['heading_level'] ) ? (int) $args['heading_level'] : 3;
$heading      = 'h' . max( 2, min( 6, $heading ) );
$cover_tag    = '' !== $read_url ? 'a' : 'div';

$work_title = isset( $work['title'] ) && is_scalar( $work['title'] ) ? trim( (string) $work['title'] ) : '';
if ( '' === $work_title ) {
	$work_title = __( 'Untitled', 'publica-now' );
}

$kind        = isset( $work['kind'] ) && is_string( $work['kind'] ) ? $work['kind'] : 'other';
$author      = isset( $work['author'] ) && is_scalar( $work['author'] ) ? trim( (string) $work['author'] ) : '';
$cover       = isset( $work['cover_url'] ) && is_string( $work['cover_url'] ) ? $work['cover_url'] : '';
$rating      = isset( $work['rating'] ) && is_array( $work['rating'] ) ? $work['rating'] : null;
$excerpt     = $show_excerpt && isset( $work['description'] ) && is_string( $work['description'] ) ? Formatting::excerpt( $work['description'], 18 ) : '';
$is_free     = ! empty( $work['is_free'] );
$currency    = isset( $work['currency'] ) && is_string( $work['currency'] ) ? $work['currency'] : 'USD';
$price_cents = isset( $work['price_cents'] ) && is_numeric( $work['price_cents'] ) ? (int) $work['price_cents'] : null;
$list_cents  = isset( $work['list_price_cents'] ) && is_numeric( $work['list_price_cents'] ) ? (int) $work['list_price_cents'] : null;
$discount    = isset( $work['discount'] ) && is_array( $work['discount'] ) ? $work['discount'] : null;
$print       = isset( $work['print'] ) && is_array( $work['print'] ) ? $work['print'] : null;
$print_cents = $print && isset( $print['price_cents'] ) && is_numeric( $print['price_cents'] ) ? (int) $print['price_cents'] : null;
$print_curr  = $print && ! empty( $print['currency'] ) && is_string( $print['currency'] ) ? $print['currency'] : $currency;

// A print-only work is priced by its paperback.
if ( 'print' === $kind && null !== $print_cents ) {
	$price_cents = $print_cents;
	$currency    = $print_curr;
	$list_cents  = null;
	$discount    = null;
	$is_free     = false;
}

$on_sale   = ! $is_free && null !== $price_cents && null !== $list_cents && $list_cents > $price_cents;
$sale_ends = $on_sale && $discount && ! empty( $discount['ends_at'] ) ? Formatting::sale_ends( (string) $discount['ends_at'] ) : '';
?>
<div class="publicanow-inline publicanow-inline--<?php echo esc_attr( $kind ); ?>">
	<?php if ( 'a' === $cover_tag ) : ?>
		<a class="publicanow-inline__cover" href="<?php echo esc_url( $read_url ); ?>" rel="noopener" tabindex="-1" aria-hidden="true"<?php echo '' !== $target ? ' target="' . esc_attr( $target ) . '"' : ''; ?>>
	<?php else : ?>
		<div class="publicanow-inline__cover">
	<?php endif; ?>
		<?php if ( '' !== $cover ) : ?>
			<img class="publicanow-cover" src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( $work_title ); ?>" width="120" height="180" loading="lazy" decoding="async" />
		<?php else : ?>
			<span class="publicanow-cover publicanow-cover--fallback <?php echo esc_attr( $hue_class ); ?>" role="img" aria-label="<?php echo esc_attr( $work_title ); ?>"><span class="publicanow-cover__title" dir="auto"><?php echo esc_html( $work_title ); ?></span></span>
		<?php endif; ?>
	</<?php echo tag_escape( $cover_tag ); ?>>

	<div class="publicanow-inline__body">
		<<?php echo tag_escape( $heading ); ?> class="publicanow-inline__title" dir="auto">
			<?php if ( '' !== $read_url ) : ?>
				<a href="<?php echo esc_url( $read_url ); ?>" rel="noopener"<?php echo '' !== $target ? ' target="' . esc_attr( $target ) . '"' : ''; ?>><?php echo esc_html( $work_title ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $work_title ); ?>
			<?php endif; ?>
		</<?php echo tag_escape( $heading ); ?>>

		<p class="publicanow-inline__meta">
			<?php if ( $show_type ) : ?>
				<span class="publicanow-badge publicanow-badge--<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( Formatting::kind_label( $kind ) ); ?></span>
			<?php endif; ?>
			<?php if ( $show_author && '' !== $author ) : ?>
				<span class="publicanow-inline__author" dir="auto">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: author name. */
							_x( 'by %s', 'author byline', 'publica-now' ),
							'<bdi>' . esc_html( $author ) . '</bdi>'
						),
						array( 'bdi' => array() )
					);
					?>
				</span>
			<?php endif; ?>
			<?php if ( $show_rating && Formatting::has_rating( $rating ) ) : ?>
				<span class="publicanow-inline__rating" role="img" aria-label="<?php echo esc_attr( Formatting::rating_aria( $rating ) ); ?>"><bdi><?php echo esc_html( Formatting::rating_text( $rating ) ); ?></bdi></span>
			<?php endif; ?>
			<?php if ( $is_free ) : ?>
				<span class="publicanow-price publicanow-price--free"><span class="publicanow-price__free"><?php esc_html_e( 'Free', 'publica-now' ); ?></span></span>
			<?php elseif ( null !== $price_cents ) : ?>
				<span class="publicanow-price<?php echo $on_sale ? ' publicanow-price--sale' : ''; ?>">
					<span class="publicanow-price__current"><bdi><?php echo esc_html( Formatting::price( $price_cents, $currency ) ); ?></bdi></span>
					<?php if ( $on_sale ) : ?>
						<s class="publicanow-price__list"><span class="publicanow-sr-only"><?php esc_html_e( 'Was', 'publica-now' ); ?> </span><bdi><?php echo esc_html( Formatting::price( $list_cents, $currency ) ); ?></bdi></s>
						<?php if ( '' !== $sale_ends ) : ?>
							<span class="publicanow-price__ends">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: localised date the sale ends. */
										__( 'ends %s', 'publica-now' ),
										'<bdi>' . esc_html( $sale_ends ) . '</bdi>'
									),
									array( 'bdi' => array() )
								);
								?>
							</span>
						<?php endif; ?>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</p>

		<?php if ( '' !== $excerpt ) : ?>
			<p class="publicanow-inline__excerpt" dir="auto"><?php echo esc_html( $excerpt ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $buttons ) ) : ?>
		<div class="publicanow-inline__actions">
			<?php foreach ( $buttons as $button ) : ?>
				<?php
				if ( ! is_array( $button ) || empty( $button['href'] ) || empty( $button['label'] ) ) {
					continue;
				}
				?>
				<a class="publicanow-button publicanow-button--<?php echo ! empty( $button['primary'] ) ? 'primary' : 'secondary'; ?> publicanow-button--<?php echo esc_attr( isset( $button['kind'] ) ? $button['kind'] : 'buy' ); ?>" href="<?php echo esc_url( $button['href'] ); ?>" rel="noopener"<?php echo '' !== $target ? ' target="' . esc_attr( $target ) . '"' : ''; ?>><?php echo esc_html( $button['label'] ); ?></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
