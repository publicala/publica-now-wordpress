<?php
/**
 * Fallback state: nothing to show, or the API could not be reached.
 *
 * Visitors get a quiet sentence and, when we know the creator, a link to
 * their publica.now page. Administrators additionally see the reason in a
 * <small>. The outer wrapper (publicanow publicanow-{surface} publicanow-empty)
 * is printed by the Renderer. Override by copying to
 * {theme}/publica-now/empty.php. Variables arrive in $args:
 *
 * @var array $args {
 *     @type string        $surface       catalog|work|buy-button.
 *     @type string        $message       Visitor-facing sentence ('' allowed).
 *     @type string        $link_url      Attributed link to the creator page, or ''.
 *     @type string        $link_text     Link label, or ''.
 *     @type string        $admin_message Reason, already limited to manage_options users ('' otherwise).
 *     @type bool          $inline        True when the wrapper is a <span> (buy-button).
 *     @type string        $target        "_blank" or ''.
 *     @type WP_Error|null $error         The underlying error, if any.
 * }
 *
 * @package PublicaNow
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template scope is local (included from Renderer::include_template()).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message       = isset( $args['message'] ) ? (string) $args['message'] : '';
$link_url      = isset( $args['link_url'] ) ? (string) $args['link_url'] : '';
$link_text     = isset( $args['link_text'] ) ? (string) $args['link_text'] : '';
$admin_message = isset( $args['admin_message'] ) ? (string) $args['admin_message'] : '';
$target        = isset( $args['target'] ) ? (string) $args['target'] : '';
$text_tag      = ! empty( $args['inline'] ) ? 'span' : 'p';

if ( '' === $link_text ) {
	$link_url = '';
}
?>
<?php if ( '' !== $message || '' !== $link_url ) : ?>
	<<?php echo tag_escape( $text_tag ); ?> class="publicanow-empty__text">
		<?php if ( '' !== $message ) : ?>
			<?php echo esc_html( $message ); ?>
		<?php endif; ?>
		<?php if ( '' !== $link_url ) : ?>
			<a class="publicanow-empty__link" href="<?php echo esc_url( $link_url ); ?>" rel="noopener"<?php echo '' !== $target ? ' target="' . esc_attr( $target ) . '"' : ''; ?>><?php echo esc_html( $link_text ); ?></a>
		<?php endif; ?>
	</<?php echo tag_escape( $text_tag ); ?>>
<?php endif; ?>
<?php if ( '' !== $admin_message ) : ?>
	<small class="publicanow-empty__admin"><?php echo esc_html( $admin_message ); ?></small>
<?php endif; ?>
