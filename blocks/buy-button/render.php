<?php
/**
 * Server render for the publica-now/buy-button block.
 *
 * WordPress includes this file with $attributes, $content and $block in
 * scope and captures the output. Block-supports wrapper attributes are
 * handed to the Renderer, which merges them onto the outer element.
 *
 * @var array    $attributes Block attributes (defaults from block.json applied).
 * @var string   $content    Inner blocks HTML (none for this block).
 * @var WP_Block $block      Block instance.
 *
 * @package PublicaNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$publicanow_atts                       = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();
$publicanow_atts['wrapper_attributes'] = get_block_wrapper_attributes();

echo \PublicaNow\Renderer::instance()->button( $publicanow_atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the Renderer and its templates escape at output.
