<?php
/**
 * Formatting helpers: prices, kind labels, sale dates, excerpts, ratings.
 *
 * Pure functions with no side effects so templates (including theme
 * overrides in {theme}/publica-now/) can call them freely. Everything here
 * returns plain text; escaping is the caller's job at the point of output.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static formatting helpers shared by templates and structured data.
 */
final class Formatting {

	/**
	 * Currency code → symbol for the codes publica.now creators actually use.
	 * Anything else falls back to "12.99 XXX" so we never guess a symbol.
	 */
	const SYMBOLS = array(
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'BRL' => 'R$',
		'MXN' => '$',
		'ARS' => '$',
		'CLP' => '$',
		'COP' => '$',
		'JPY' => '¥',
	);

	/**
	 * Currencies whose minor unit is the unit itself (price_cents is whole yen).
	 */
	const ZERO_DECIMAL = array( 'JPY' );

	/**
	 * Number of hue buckets available in the CSS for typographic fallback covers.
	 */
	const HUE_BUCKETS = 12;

	/**
	 * Number of decimals a currency is displayed with.
	 *
	 * @param string $currency ISO 4217 code, any case.
	 * @return int
	 */
	public static function currency_decimals( string $currency ): int {
		return in_array( self::currency( $currency ), self::ZERO_DECIMAL, true ) ? 0 : 2;
	}

	/**
	 * Machine-readable decimal price ("12.99", "1200") for structured data.
	 *
	 * Never localised: schema.org wants a dot decimal and no grouping.
	 *
	 * @param int    $cents    Amount in the currency's minor unit.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	public static function decimal_price( int $cents, string $currency ): string {
		$decimals = self::currency_decimals( $currency );

		return number_format( max( 0, $cents ) / pow( 10, $decimals ), $decimals, '.', '' );
	}

	/**
	 * Human price: "$12.99", "R$12,99" (pt_BR locale), "¥1200", or "12.99 CHF".
	 *
	 * Grouping and the decimal mark follow the site locale via
	 * number_format_i18n(); the symbol map is intentionally short so we never
	 * show a wrong symbol for a code we do not know.
	 *
	 * @param int|null $cents    Amount in minor units; null renders as ''.
	 * @param string   $currency ISO 4217 code, any case; '' means USD.
	 * @return string
	 */
	public static function price( ?int $cents, string $currency ): string {
		if ( null === $cents ) {
			return '';
		}

		$currency = self::currency( $currency );
		$decimals = self::currency_decimals( $currency );
		$number   = number_format_i18n( max( 0, $cents ) / pow( 10, $decimals ), $decimals );

		if ( isset( self::SYMBOLS[ $currency ] ) ) {
			return self::SYMBOLS[ $currency ] . $number;
		}

		return $number . ' ' . $currency;
	}

	/**
	 * Translatable badge label for a normalised work kind.
	 *
	 * @param string $kind ebook|audiobook|music|video|course|zine|photography|design|print|other.
	 * @return string
	 */
	public static function kind_label( string $kind ): string {
		$labels = array(
			'ebook'       => __( 'Ebook', 'publica-now' ),
			'audiobook'   => __( 'Audiobook', 'publica-now' ),
			'music'       => __( 'Music', 'publica-now' ),
			'video'       => __( 'Video', 'publica-now' ),
			'course'      => __( 'Course', 'publica-now' ),
			'zine'        => __( 'Zine', 'publica-now' ),
			'photography' => __( 'Photography', 'publica-now' ),
			'design'      => __( 'Design', 'publica-now' ),
			'print'       => __( 'Printed book', 'publica-now' ),
			'other'       => __( 'Work', 'publica-now' ),
		);

		$kind = strtolower( trim( $kind ) );

		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $labels['other'];
	}

	/**
	 * Map the stored publica.now content_type to the normalised kind.
	 *
	 * The API stores "literary" for ebooks (see docs/PLAN.md §3); everything
	 * else is already the kind name. Unknown values become "other" so a new
	 * content type upstream degrades to a generic card instead of breaking.
	 *
	 * @param string $content_type Stored value from the API.
	 * @return string
	 */
	public static function kind_from_content_type( string $content_type ): string {
		$map = array(
			'literary'    => 'ebook',
			'ebook'       => 'ebook',
			'audiobook'   => 'audiobook',
			'music'       => 'music',
			'video'       => 'video',
			'course'      => 'course',
			'zine'        => 'zine',
			'photography' => 'photography',
			'design'      => 'design',
			'print'       => 'print',
		);

		$content_type = strtolower( trim( $content_type ) );

		return isset( $map[ $content_type ] ) ? $map[ $content_type ] : 'other';
	}

	/**
	 * Localised sale end date in the site's date format and timezone.
	 *
	 * @param string $iso RFC 3339 / ISO 8601 timestamp from the API.
	 * @return string '' when the timestamp cannot be parsed.
	 */
	public static function sale_ends( string $iso ): string {
		$timestamp = strtotime( $iso );

		if ( false === $timestamp ) {
			return '';
		}

		$format = (string) get_option( 'date_format' );
		if ( '' === $format ) {
			$format = 'F j, Y';
		}

		return (string) wp_date( $format, $timestamp );
	}

	/**
	 * Plain-text excerpt of a description (HTML stripped, whitespace collapsed).
	 *
	 * @param string $text  Raw description; may contain HTML.
	 * @param int    $words Word budget.
	 * @return string
	 */
	public static function excerpt( string $text, int $words = 28 ): string {
		$text = wp_strip_all_tags( $text, true );

		if ( '' === $text ) {
			return '';
		}

		return wp_trim_words( $text, max( 1, $words ), '…' );
	}

	/**
	 * Compact visible rating text: "★ 4.5 (12)".
	 *
	 * @param array $rating {average: float, count: int}.
	 * @return string '' when there is no usable rating.
	 */
	public static function rating_text( array $rating ): string {
		if ( ! self::has_rating( $rating ) ) {
			return '';
		}

		return sprintf(
			/* translators: 1: average rating with one decimal, 2: number of ratings. */
			_x( '★ %1$s (%2$s)', 'rating summary', 'publica-now' ),
			number_format_i18n( (float) $rating['average'], 1 ),
			number_format_i18n( (int) $rating['count'] )
		);
	}

	/**
	 * Screen-reader label for the rating (the star glyph alone reads badly).
	 *
	 * @param array $rating {average: float, count: int}.
	 * @return string
	 */
	public static function rating_aria( array $rating ): string {
		if ( ! self::has_rating( $rating ) ) {
			return '';
		}

		$count = (int) $rating['count'];

		return sprintf(
			/* translators: 1: average rating with one decimal, 2: number of ratings. */
			_n( 'Rated %1$s out of 5 by %2$s reader', 'Rated %1$s out of 5 by %2$s readers', $count, 'publica-now' ),
			number_format_i18n( (float) $rating['average'], 1 ),
			number_format_i18n( $count )
		);
	}

	/**
	 * Whether a normalised rating array carries something worth showing.
	 *
	 * @param array|null $rating Normalised rating or null.
	 * @return bool
	 */
	public static function has_rating( $rating ): bool {
		return is_array( $rating )
			&& isset( $rating['average'], $rating['count'] )
			&& (int) $rating['count'] > 0
			&& (float) $rating['average'] > 0;
	}

	/**
	 * Deterministic CSS class for the typographic fallback cover.
	 *
	 * The hue is derived from the slug so the same work always gets the same
	 * colour, without any inline style (the stylesheet defines the buckets).
	 *
	 * @param string $seed Slug or id.
	 * @return string e.g. "publicanow-hue-7".
	 */
	public static function cover_hue_class( string $seed ): string {
		$bucket = abs( crc32( $seed ) ) % self::HUE_BUCKETS;

		return 'publicanow-hue-' . $bucket;
	}

	/**
	 * Normalise a currency code: upper-case, USD when empty.
	 *
	 * @param string $currency Any case, possibly ''.
	 * @return string
	 */
	public static function currency( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );

		return '' === $currency ? 'USD' : $currency;
	}
}
