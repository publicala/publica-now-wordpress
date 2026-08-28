<?php
/**
 * Script dependencies for blocks/work/index.js.
 *
 * WordPress reads {editorScript}.asset.php when it registers the block.json
 * "editorScript" handle; without it the wp.* globals the script relies on
 * would not be guaranteed to load first.
 *
 * @package PublicaNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-server-side-render',
		'wp-api-fetch',
		'wp-i18n',
		'wp-data',
	),
	'version'      => defined( 'PUBLICANOW_VERSION' ) ? PUBLICANOW_VERSION : '1.0.0',
);
