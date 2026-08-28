<?php
/**
 * Transient cache: a fresh copy with a short TTL plus a stale copy kept for
 * seven days, so a publica.now outage never blanks a page.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache helpers. Everything is static: the cache has no state of its own
 * beyond WordPress transients.
 */
final class Cache {

	/**
	 * Object-cache group. Flushed on purge when the drop-in supports groups.
	 *
	 * The plugin itself only ever writes transients; the flush is defensive,
	 * for drop-ins that mirror transients into a named group.
	 */
	const GROUP = 'publicanow';

	/**
	 * Prefix of the fresh copy: publicanow_c_{md5}.
	 */
	const FRESH_PREFIX = 'publicanow_c_';

	/**
	 * Prefix of the stale copy: publicanow_s_{md5}.
	 */
	const STALE_PREFIX = 'publicanow_s_';

	/**
	 * Prefix of the short-lived failure marker: publicanow_f_{md5}.
	 */
	const FAIL_PREFIX = 'publicanow_f_';

	/**
	 * How long a failed read is remembered, in seconds. Long enough that an
	 * outage cannot make every page view pay its own blocking request, short
	 * enough that recovery is invisible.
	 */
	const FAIL_TTL = 60;

	/**
	 * Option holding the cache generation. Bumped on purge so that entries
	 * living in a persistent object cache (where a LIKE delete cannot reach)
	 * are orphaned instead of served.
	 */
	const GENERATION_OPTION = 'publicanow_cache_gen';

	/**
	 * Default fresh TTL, in seconds (15 minutes).
	 */
	const DEFAULT_TTL = 900;

	/**
	 * Stale TTL, in seconds (7 days).
	 */
	const STALE_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Fresh TTL: the cache_ttl setting, then the publicanow_cache_ttl filter.
	 *
	 * @return int Seconds, never below 60.
	 */
	public static function ttl(): int {
		$ttl = (int) Settings::get( 'cache_ttl', self::DEFAULT_TTL );

		/**
		 * Filters how long a fresh API response is cached.
		 *
		 * @param int $ttl Seconds.
		 */
		$ttl = (int) apply_filters( 'publicanow_cache_ttl', $ttl );

		// A too-short TTL would hammer the API and trip its rate limit.
		return max( 60, $ttl );
	}

	/**
	 * Current cache generation.
	 *
	 * @return int
	 */
	public static function generation(): int {
		return (int) get_option( self::GENERATION_OPTION, 1 );
	}

	/**
	 * The hashed part of every key: generation, environment and logical name.
	 *
	 * The environment (API base + sandbox flag) is part of the hash so that
	 * turning on PUBLICANOW_SANDBOX, or filtering publicanow_api_base to
	 * staging, cannot serve production data — and vice versa.
	 *
	 * The md5 keeps the transient name under the 172-character limit and
	 * makes it safe regardless of what the logical name contains.
	 *
	 * @param string $name Logical cache name, e.g. "works:my-slug".
	 * @return string
	 */
	private static function hash( string $name ): string {
		return md5( self::generation() . '|' . Api_Client::instance()->environment() . '|' . $name );
	}

	/**
	 * Transient name of the fresh copy for a logical cache name.
	 *
	 * @param string $name Logical cache name, e.g. "works:my-slug".
	 * @return string
	 */
	public static function fresh_key( string $name ): string {
		return self::FRESH_PREFIX . self::hash( $name );
	}

	/**
	 * Transient name of the stale copy for a logical cache name.
	 *
	 * @param string $name Logical cache name.
	 * @return string
	 */
	public static function stale_key( string $name ): string {
		return self::STALE_PREFIX . self::hash( $name );
	}

	/**
	 * Transient name of the failure marker for a logical cache name.
	 *
	 * @param string $name Logical cache name.
	 * @return string
	 */
	public static function fail_key( string $name ): string {
		return self::FAIL_PREFIX . self::hash( $name );
	}

	/**
	 * Read the fresh copy.
	 *
	 * @param string $name Logical cache name.
	 * @return mixed|null The cached value, or null when absent/expired.
	 */
	public static function get( string $name ) {
		return self::unwrap( get_transient( self::fresh_key( $name ) ) );
	}

	/**
	 * Read the stale copy (up to 7 days old).
	 *
	 * @param string $name Logical cache name.
	 * @return mixed|null
	 */
	public static function get_stale( string $name ) {
		return self::unwrap( get_transient( self::stale_key( $name ) ) );
	}

	/**
	 * Age of the stale copy in seconds, or null when there is none.
	 *
	 * @param string $name Logical cache name.
	 * @return int|null
	 */
	public static function stale_age( string $name ) {
		$raw = get_transient( self::stale_key( $name ) );

		if ( ! is_array( $raw ) || ! isset( $raw['at'] ) ) {
			return null;
		}

		return max( 0, time() - (int) $raw['at'] );
	}

	/**
	 * Store a value as both the fresh and the stale copy.
	 *
	 * @param string   $name  Logical cache name.
	 * @param mixed    $value Value to store. Any serialisable value, including false.
	 * @param int|null $ttl   Fresh TTL override in seconds; null uses ttl().
	 * @return void
	 */
	public static function set( string $name, $value, $ttl = null ) {
		$wrapped = self::wrap( $value );
		$ttl     = null === $ttl ? self::ttl() : max( 60, (int) $ttl );

		set_transient( self::fresh_key( $name ), $wrapped, $ttl );
		set_transient( self::stale_key( $name ), $wrapped, self::STALE_TTL );
	}

	/**
	 * Remember that a read failed, so the next page view does not repeat a
	 * blocking request that is going to fail again. The error itself is stored
	 * and replayed, so the caller keeps its contract.
	 *
	 * @param string    $name  Logical cache name.
	 * @param \WP_Error $error The failure to replay.
	 * @return void
	 */
	public static function set_failure( string $name, $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		set_transient(
			self::fail_key( $name ),
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			),
			self::FAIL_TTL
		);
	}

	/**
	 * The remembered failure for a logical name, or null when there is none.
	 *
	 * @param string $name Logical cache name.
	 * @return \WP_Error|null
	 */
	public static function get_failure( string $name ) {
		$raw = get_transient( self::fail_key( $name ) );

		if ( ! is_array( $raw ) || empty( $raw['code'] ) ) {
			return null;
		}

		return new \WP_Error( (string) $raw['code'], (string) $raw['message'], isset( $raw['data'] ) ? $raw['data'] : array() );
	}

	/**
	 * Forget a remembered failure (a success, or an explicit admin refresh).
	 *
	 * @param string $name Logical cache name.
	 * @return void
	 */
	public static function clear_failure( string $name ) {
		delete_transient( self::fail_key( $name ) );
	}

	/**
	 * Whether a fresh copy exists.
	 *
	 * @param string $name Logical cache name.
	 * @return bool
	 */
	public static function has( string $name ): bool {
		return false !== get_transient( self::fresh_key( $name ) );
	}

	/**
	 * Purge every cached API response (fresh, stale and failure markers). The
	 * access token is left alone: it is not catalog data and re-minting it
	 * costs a request.
	 *
	 * This destroys the 7-day outage copy, so it must only run when the caller
	 * knows fresh data can be fetched — Settings::refresh() re-reads the API
	 * first and purges only after that succeeds.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;

		$patterns = array(
			'_transient_' . self::FRESH_PREFIX,
			'_transient_timeout_' . self::FRESH_PREFIX,
			'_transient_' . self::STALE_PREFIX,
			'_transient_timeout_' . self::STALE_PREFIX,
			'_transient_' . self::FAIL_PREFIX,
			'_transient_timeout_' . self::FAIL_PREFIX,
		);

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient purge has no API equivalent.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $pattern ) . '%'
				)
			);
		}

		/*
		 * Under a persistent object cache the transients above are not in the
		 * options table. Bumping the generation changes every key, so the
		 * old entries are never read again and expire on their own.
		 */
		update_option( self::GENERATION_OPTION, self::generation() + 1, true );

		if ( function_exists( 'wp_cache_flush_group' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}

		// The options table row changed; make sure alloptions is not stale.
		wp_cache_delete( 'alloptions', 'options' );

		/**
		 * Fires after the plugin's catalog cache has been purged.
		 */
		do_action( 'publicanow_cache_purged' );
	}

	/**
	 * Wrap a value so that false/empty values survive the transient API's
	 * "false means missing" convention, and remember when it was stored.
	 *
	 * @param mixed $value Value.
	 * @return array{data:mixed,at:int}
	 */
	private static function wrap( $value ): array {
		return array(
			'data' => $value,
			'at'   => time(),
		);
	}

	/**
	 * Inverse of wrap().
	 *
	 * @param mixed $raw Raw transient value.
	 * @return mixed|null
	 */
	private static function unwrap( $raw ) {
		if ( ! is_array( $raw ) || ! array_key_exists( 'data', $raw ) ) {
			return null;
		}

		return $raw['data'];
	}
}
