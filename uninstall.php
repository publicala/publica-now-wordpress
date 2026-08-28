<?php
/**
 * Uninstall: revoke the token (best effort), delete options, transients and
 * the per-user notice flags. Multisite-aware.
 *
 * WordPress loads this file on its own, without the plugin's main file, so it
 * is self-contained: no autoloader, no plugin constants assumed.
 *
 * @package PublicaNow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Revoke the cached access token at publica.now. Best effort: a failure here
 * only means the token expires on its own within the hour.
 *
 * @return void
 */
function publicanow_uninstall_revoke_token() {
	$base = defined( 'PUBLICANOW_API_BASE' ) ? PUBLICANOW_API_BASE : 'https://publica.now';
	$base = (string) apply_filters( 'publicanow_api_base', $base );
	$base = preg_match( '#^https?://[^/\s]+$#i', untrailingslashit( trim( $base ) ) )
		? untrailingslashit( trim( $base ) )
		: 'https://publica.now';

	$sandbox = defined( 'PUBLICANOW_SANDBOX' ) && PUBLICANOW_SANDBOX;
	$sandbox = (bool) apply_filters( 'publicanow_sandbox', $sandbox );

	// Must match Api_Client::token_transient().
	$token  = get_transient( 'publicanow_token_' . substr( md5( $base . '|' . ( $sandbox ? '1' : '0' ) ), 0, 12 ) );
	$client = get_option( 'publicanow_oauth' );

	if ( ! is_string( $token ) || '' === $token || ! is_array( $client ) || empty( $client['client_id'] ) || empty( $client['client_secret'] ) ) {
		return;
	}

	wp_remote_post(
		$base . '/oauth/revoke',
		array(
			'timeout'     => 5,
			'redirection' => 0,
			'user-agent'  => 'PublicaNowWP/uninstall (+' . home_url() . ')',
			'headers'     => array(
				'Accept'        => 'application/json',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth requires base64.
				'Authorization' => 'Basic ' . base64_encode( $client['client_id'] . ':' . $client['client_secret'] ),
			),
			'body'        => array(
				'token'           => $token,
				'token_type_hint' => 'access_token',
				'client_id'       => $client['client_id'],
				'client_secret'   => $client['client_secret'],
			),
		)
	);
}

/**
 * Remove everything the plugin stored on the current site.
 *
 * @return void
 */
function publicanow_uninstall_site() {
	global $wpdb;

	publicanow_uninstall_revoke_token();

	foreach ( array( 'publicanow_settings', 'publicanow_oauth', 'publicanow_creator', 'publicanow_activated_at', 'publicanow_cache_gen' ) as $option ) {
		delete_option( $option );
	}

	/*
	 * Access tokens (publicanow_token_*), catalog caches (publicanow_c_*,
	 * publicanow_s_*) and failure markers (publicanow_f_*), values and
	 * timeouts. The LIKE sweep below covers all of them by prefix; this call
	 * also clears the pre-1.0 unsuffixed name should one ever exist.
	 */
	delete_transient( 'publicanow_token' );

	// Catalog caches: publicanow_c_*, publicanow_s_*, publicanow_f_* and tokens.
	foreach ( array( '_transient_publicanow_', '_transient_timeout_publicanow_' ) as $pattern ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup has no API equivalent.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $pattern ) . '%'
			)
		);
	}

	if ( function_exists( 'wp_cache_flush_group' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'publicanow' );
	}

	wp_cache_delete( 'alloptions', 'options' );
}

if ( is_multisite() ) {
	$publicanow_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $publicanow_site_ids as $publicanow_site_id ) {
		switch_to_blog( (int) $publicanow_site_id );
		publicanow_uninstall_site();
		restore_current_blog();
	}
} else {
	publicanow_uninstall_site();
}

// User meta lives in one shared table, so once is enough on multisite too.
delete_metadata( 'user', 0, 'publicanow_notice_dismissed', '', true );
