<?php
/**
 * Outbound link builders: buy / read / print / creator URLs with attribution.
 *
 * Every link that leaves the site for publica.now passes through here so the
 * three utm_* arguments (which publica.now's CaptureAttribution middleware
 * turns into end-to-end sale attribution) are never forgotten. URLs are
 * returned unescaped; templates escape with esc_url() at output.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL builders and the button-choice rule (docs/PLAN.md §7).
 */
final class Links {

	const UTM_MEDIUM   = 'wordpress_plugin';
	const UTM_CAMPAIGN = 'publica-now-plugin';

	/**
	 * Base URL of the publica.now instance the plugin talks to.
	 *
	 * Mirrors the API client's filter so links and API calls agree when a
	 * developer points the plugin at sandbox/staging.
	 *
	 * @return string Without trailing slash.
	 */
	public static function base(): string {
		$base = defined( 'PUBLICANOW_API_BASE' ) ? PUBLICANOW_API_BASE : 'https://publica.now';

		/** This filter is documented in includes/class-api-client.php. */
		$base = (string) apply_filters( 'publicanow_api_base', $base );

		return untrailingslashit( $base );
	}

	/**
	 * The attribution arguments appended to every outbound link.
	 *
	 * @param array  $work Normalised work (or creator, for creator links).
	 * @param string $kind buy|read|print|creator.
	 * @return array<string,string>
	 */
	public static function attribution_args( array $work, string $kind ): array {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		$args = array(
			'utm_source'   => is_string( $host ) ? $host : '',
			'utm_medium'   => self::UTM_MEDIUM,
			'utm_campaign' => self::UTM_CAMPAIGN,
		);

		/**
		 * Filter the query arguments added to outbound publica.now links.
		 *
		 * @param array  $args Query arguments (unencoded values).
		 * @param array  $work Normalised work the link belongs to.
		 * @param string $kind buy|read|print|creator.
		 */
		$args = apply_filters( 'publicanow_link_args', $args, $work, $kind );

		return is_array( $args ) ? $args : array();
	}

	/**
	 * Append attribution to a URL, keeping whatever query string it already has.
	 *
	 * Note that add_query_arg() does not encode values, so we rawurlencode them here;
	 * keys are reduced to a safe character set because they may come from the
	 * publicanow_link_args filter.
	 *
	 * @param string $url  API-provided URL.
	 * @param array  $work Normalised work (for the filter).
	 * @param string $kind buy|read|print|creator.
	 * @return string '' when $url is empty.
	 */
	public static function with_attribution( string $url, array $work, string $kind ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$encoded = array();
		foreach ( self::attribution_args( $work, $kind ) as $key => $value ) {
			$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
			if ( '' === $key || null === $value || '' === (string) $value ) {
				continue;
			}
			$encoded[ $key ] = rawurlencode( (string) $value );
		}

		if ( empty( $encoded ) ) {
			return $url;
		}

		return add_query_arg( $encoded, $url );
	}

	/**
	 * Hosted checkout link for a paid digital work.
	 *
	 * Falls back to {base}/checkout/{slug} when the API predates Layer 1
	 * (docs/PLAN.md §3) and there is no checkout_url in the payload.
	 *
	 * @param array $work Normalised work.
	 * @return string '' for free or print-only works without a checkout.
	 */
	public static function buy( array $work ): string {
		$url = isset( $work['checkout_url'] ) && is_string( $work['checkout_url'] ) ? $work['checkout_url'] : '';

		if ( '' === $url && empty( $work['is_free'] ) && 'print' !== self::kind( $work ) && '' !== self::slug( $work ) ) {
			$url = self::base() . '/checkout/' . rawurlencode( self::slug( $work ) );
		}

		return self::with_attribution( $url, $work, 'buy' );
	}

	/**
	 * Public work page (where free works are read from).
	 *
	 * @param array $work Normalised work.
	 * @return string
	 */
	public static function read( array $work ): string {
		$url = isset( $work['url'] ) && is_string( $work['url'] ) ? $work['url'] : '';

		if ( '' === $url && '' !== self::slug( $work ) ) {
			$creator = isset( $work['creator']['slug'] ) ? (string) $work['creator']['slug'] : '';
			if ( '' !== $creator ) {
				$url = self::base() . '/creators/' . rawurlencode( $creator ) . '/works/' . rawurlencode( self::slug( $work ) );
			}
		}

		return self::with_attribution( $url, $work, 'read' );
	}

	/**
	 * Printed-copy order page, only when the work has an orderable print edition.
	 *
	 * @param array $work Normalised work.
	 * @return string '' when there is no print edition.
	 */
	public static function print_order( array $work ): string {
		if ( empty( $work['print'] ) || ! is_array( $work['print'] ) ) {
			return '';
		}

		$url = isset( $work['print']['order_url'] ) && is_string( $work['print']['order_url'] ) ? $work['print']['order_url'] : '';

		if ( '' === $url && '' !== self::slug( $work ) ) {
			$url = self::base() . '/order/' . rawurlencode( self::slug( $work ) );
		}

		return self::with_attribution( $url, $work, 'print' );
	}

	/**
	 * Public creator page.
	 *
	 * The publicanow_link_args filter receives the creator array in the $work
	 * position with kind "creator".
	 *
	 * @param array $creator Normalised creator (at least slug or url).
	 * @return string
	 */
	public static function creator( array $creator ): string {
		$url = isset( $creator['url'] ) && is_string( $creator['url'] ) ? $creator['url'] : '';

		if ( '' === $url && ! empty( $creator['slug'] ) ) {
			$url = self::base() . '/creators/' . rawurlencode( (string) $creator['slug'] );
		}

		return self::with_attribution( $url, $creator, 'creator' );
	}

	/**
	 * Whether outbound links open in a new tab (Settings → Publica.now).
	 *
	 * Reads the option directly so the front end never depends on the admin
	 * classes being loaded.
	 *
	 * @return bool
	 */
	public static function open_in_new_tab(): bool {
		$settings = get_option( 'publicanow_settings', array() );

		return is_array( $settings ) && ! empty( $settings['open_in_new_tab'] );
	}

	/**
	 * Value for the target attribute of outbound links.
	 *
	 * @return string "_blank" or ''.
	 */
	public static function target(): string {
		return self::open_in_new_tab() ? '_blank' : '';
	}

	/**
	 * Site-wide replacement for the "Buy" label (Settings → Publica.now).
	 *
	 * @return string '' when the default should be used.
	 */
	public static function button_text_setting(): string {
		$settings = get_option( 'publicanow_settings', array() );

		if ( ! is_array( $settings ) || ! isset( $settings['button_text'] ) ) {
			return '';
		}

		return trim( (string) $settings['button_text'] );
	}

	/**
	 * The buttons a work should show, in display order.
	 *
	 * Rule (docs/PLAN.md §7): print-kind → print only; free → Read free
	 * (+ Order paperback when a print edition exists); paid → Buy (+ Order
	 * paperback). When none applies (a print work whose edition is not
	 * orderable yet) we still link to the work page so the card is never a
	 * dead end.
	 *
	 * Labels: the site setting button_text replaces "Buy" only (renaming
	 * "Read free" would hide that the work is free); the per-block/shortcode
	 * $button_text replaces whichever button is primary because the author
	 * typed it for this exact surface. publicanow_button_label runs last.
	 *
	 * @param array  $work        Normalised work.
	 * @param string $button_text Per-surface label override for the primary button.
	 * @return array<int,array{kind:string,label:string,href:string,primary:bool}>
	 */
	public static function buttons_for( array $work, string $button_text = '' ): array {
		$kind    = self::kind( $work );
		$is_free = ! empty( $work['is_free'] );
		$print   = self::print_order( $work );
		$buttons = array();

		if ( 'print' === $kind ) {
			if ( '' !== $print ) {
				$buttons[] = self::button( 'print', $print, true, $work, $button_text );
			}
		} elseif ( $is_free ) {
			$read = self::read( $work );
			if ( '' !== $read ) {
				$buttons[] = self::button( 'read', $read, true, $work, $button_text );
			}
			if ( '' !== $print ) {
				$buttons[] = self::button( 'print', $print, false, $work, $button_text );
			}
		} else {
			$buy = self::buy( $work );
			if ( '' !== $buy ) {
				$buttons[] = self::button( 'buy', $buy, true, $work, $button_text );
			}
			if ( '' !== $print ) {
				$buttons[] = self::button( 'print', $print, false, $work, $button_text );
			}
		}

		if ( empty( $buttons ) ) {
			$read = self::read( $work );
			if ( '' !== $read ) {
				$buttons[] = self::button( 'read', $read, true, $work, $button_text, __( 'View on Publica.now', 'publica-now' ) );
			}
		}

		return $buttons;
	}

	/**
	 * Default, translatable label for a button kind.
	 *
	 * @param string $kind buy|read|print.
	 * @return string
	 */
	public static function default_label( string $kind ): string {
		switch ( $kind ) {
			case 'buy':
				return __( 'Buy', 'publica-now' );
			case 'print':
				return __( 'Order paperback', 'publica-now' );
			default:
				return __( 'Read free', 'publica-now' );
		}
	}

	/**
	 * Build one button entry with the label precedence described in buttons_for().
	 *
	 * @param string $kind        buy|read|print.
	 * @param string $href        Attributed URL.
	 * @param bool   $primary     Whether this is the main call to action.
	 * @param array  $work        Normalised work.
	 * @param string $button_text Per-surface override for the primary button.
	 * @param string $label       Default label; '' means the kind's standard label.
	 * @return array{kind:string,label:string,href:string,primary:bool}
	 */
	private static function button( string $kind, string $href, bool $primary, array $work, string $button_text, string $label = '' ): array {
		if ( '' === $label ) {
			$label = self::default_label( $kind );
		}

		if ( 'buy' === $kind ) {
			$setting = self::button_text_setting();
			if ( '' !== $setting ) {
				$label = $setting;
			}
		}

		if ( $primary && '' !== trim( $button_text ) ) {
			$label = trim( $button_text );
		}

		/**
		 * Filter a button label before it is rendered.
		 *
		 * @param string $label Label text (unescaped).
		 * @param string $kind  buy|read|print.
		 * @param array  $work  Normalised work.
		 */
		$label = (string) apply_filters( 'publicanow_button_label', $label, $kind, $work );

		return array(
			'kind'    => $kind,
			'label'   => $label,
			'href'    => $href,
			'primary' => $primary,
		);
	}

	/**
	 * Normalised kind of a work, "other" when missing.
	 *
	 * @param array $work Normalised work.
	 * @return string
	 */
	private static function kind( array $work ): string {
		return isset( $work['kind'] ) && is_string( $work['kind'] ) ? $work['kind'] : 'other';
	}

	/**
	 * Slug of a work, '' when missing.
	 *
	 * @param array $work Normalised work.
	 * @return string
	 */
	private static function slug( array $work ): string {
		return isset( $work['slug'] ) && is_scalar( $work['slug'] ) ? (string) $work['slug'] : '';
	}
}
