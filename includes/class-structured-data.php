<?php
/**
 * Structured data: collects rendered works and prints one JSON-LD script.
 *
 * Every surface adds the works it rendered; wp_footer (priority 20) prints a
 * single <script type="application/ld+json"> with an ItemList when more than
 * one work is on the page. Offers point at publica.now (with attribution),
 * because that is where the price is honoured and the sale happens.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-LD collector (singleton).
 */
final class Structured_Data {

	/**
	 * Singleton instance.
	 *
	 * @var Structured_Data|null
	 */
	private static $instance = null;

	/**
	 * Works to describe, keyed by id (dedupes a work shown twice on one page).
	 *
	 * @var array<string,array>
	 */
	private $works = array();

	/**
	 * Whether the footer script has been printed for this request.
	 *
	 * @var bool
	 */
	private $printed = false;

	/**
	 * Singleton accessor.
	 *
	 * @return Structured_Data
	 */
	public static function instance(): Structured_Data {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook the footer output. Called from Plugin::boot().
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_footer', array( self::instance(), 'output' ), 20 );
	}

	/**
	 * Remember a rendered work.
	 *
	 * @param array $work Normalised work.
	 * @return void
	 */
	public function add( array $work ): void {
		$key = self::str( $work, 'id' );
		if ( '' === $key ) {
			$key = self::str( $work, 'slug' );
		}
		if ( '' === $key ) {
			return;
		}

		if ( ! isset( $this->works[ $key ] ) ) {
			$this->works[ $key ] = $work;
		}
	}

	/**
	 * Number of works collected so far.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->works );
	}

	/**
	 * Forget everything (tests, or a second render pass).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->works   = array();
		$this->printed = false;
	}

	/**
	 * The @graph array: one node, or one ItemList wrapping every node.
	 *
	 * @return array Empty when nothing was rendered or a filter disabled output.
	 */
	public function graph(): array {
		$nodes = array();
		foreach ( $this->works as $work ) {
			$nodes[] = $this->node( $work );
		}

		if ( count( $nodes ) > 1 ) {
			$elements = array();
			$position = 1;
			foreach ( $nodes as $node ) {
				$elements[] = array(
					'@type'    => 'ListItem',
					'position' => $position,
					'item'     => $node,
				);
				++$position;
			}

			$nodes = array(
				array(
					'@type'           => 'ItemList',
					'numberOfItems'   => count( $elements ),
					'itemListElement' => $elements,
				),
			);
		}

		/**
		 * Filter the JSON-LD graph before output. Return an empty array to disable.
		 *
		 * @param array $graph List of schema.org nodes (a single ItemList when >1 work).
		 */
		$graph = apply_filters( 'publicanow_jsonld', $nodes );

		return is_array( $graph ) ? array_values( $graph ) : array();
	}

	/**
	 * Print the JSON-LD script once.
	 *
	 * JSON_HEX_TAG (on top of the unescaped slashes/unicode the contract asks
	 * for) hex-escapes angle brackets, so a title or description containing
	 * a closing script tag can never break out of the element.
	 *
	 * @return void
	 */
	public function output(): void {
		if ( $this->printed ) {
			return;
		}
		$this->printed = true;

		$graph       = $this->graph();
		$this->works = array();

		if ( empty( $graph ) ) {
			return;
		}

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);

		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}

		echo "\n" . '<script type="application/ld+json" class="publicanow-jsonld">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built with wp_json_encode() and JSON_HEX_TAG; script context, not HTML.
	}

	/**
	 * The schema.org node for one work.
	 *
	 * Kinds map to: ebook→Book/EBook, print→Book/Paperback, audiobook→Audiobook,
	 * music→MusicAlbum, video→VideoObject, course→Course, everything else →
	 * Product + CreativeWork (multi-typed so "author" stays valid).
	 *
	 * @param array $work Normalised work.
	 * @return array
	 */
	public function node( array $work ): array {
		$kind  = self::str( $work, 'kind' );
		$kind  = '' === $kind ? 'other' : $kind;
		$title = self::str( $work, 'title' );

		$node = array(
			'@type' => self::type_for( $kind ),
			'name'  => '' !== $title ? $title : __( 'Untitled', 'publica-now' ),
		);

		$url = self::str( $work, 'url' );
		if ( '' !== $url ) {
			$node['url'] = $url;
		}

		$cover = self::str( $work, 'cover_url' );
		if ( '' !== $cover ) {
			$node['image'] = $cover;
		}

		$author = $this->person( $work );
		if ( ! empty( $author ) ) {
			$node['author'] = $author;
		}

		$description = self::str( $work, 'description' );
		if ( '' !== $description ) {
			$description = wp_trim_words( wp_strip_all_tags( $description, true ), 160, '…' );
			if ( '' !== $description ) {
				$node['description'] = $description;
			}
		}

		$language = self::str( $work, 'language' );
		if ( '' !== $language ) {
			$node['inLanguage'] = $language;
		}

		$published = self::date( self::str( $work, 'published_at' ) );
		if ( '' !== $published ) {
			$node['datePublished'] = $published;
		}

		switch ( $kind ) {
			case 'ebook':
				$node['bookFormat'] = 'https://schema.org/EBook';
				break;
			case 'print':
				$node['bookFormat'] = 'https://schema.org/Paperback';
				break;
			case 'audiobook':
				$node['bookFormat'] = 'https://schema.org/AudiobookFormat';
				break;
			case 'music':
				if ( ! empty( $author ) ) {
					$node['byArtist'] = $author;
				}
				break;
			case 'video':
				if ( '' !== $cover ) {
					$node['thumbnailUrl'] = $cover;
				}
				if ( '' !== $published ) {
					$node['uploadDate'] = $published;
				}
				break;
			case 'course':
				if ( ! empty( $author ) ) {
					$node['provider'] = $author;
				}
				break;
		}

		$offers = $this->offers( $work );
		if ( ! empty( $offers ) ) {
			$node['offers'] = 1 === count( $offers ) ? $offers[0] : $offers;
		}

		if ( isset( $work['rating'] ) && Formatting::has_rating( $work['rating'] ) ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( (float) $work['rating']['average'], 1 ),
				'ratingCount' => (int) $work['rating']['count'],
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}

		return $node;
	}

	/**
	 * The schema.org @type for a kind.
	 *
	 * @param string $kind Normalised kind.
	 * @return string|string[]
	 */
	private static function type_for( string $kind ) {
		switch ( $kind ) {
			case 'ebook':
			case 'print':
				return 'Book';
			case 'audiobook':
				return 'Audiobook';
			case 'music':
				return 'MusicAlbum';
			case 'video':
				return 'VideoObject';
			case 'course':
				return 'Course';
			default:
				return array( 'Product', 'CreativeWork' );
		}
	}

	/**
	 * Offers for a work: the digital offer (free or paid) plus a print offer.
	 *
	 * @param array $work Normalised work.
	 * @return array[]
	 */
	private function offers( array $work ): array {
		$offers   = array();
		$kind     = self::str( $work, 'kind' );
		$currency = Formatting::currency( self::str( $work, 'currency' ) );

		if ( 'print' !== $kind ) {
			if ( ! empty( $work['is_free'] ) ) {
				$url = Links::read( $work );
				if ( '' !== $url ) {
					$offers[] = $this->offer( $url, 0, $currency, $work, true );
				}
			} elseif ( isset( $work['price_cents'] ) && is_numeric( $work['price_cents'] ) ) {
				$url = Links::buy( $work );
				if ( '' !== $url ) {
					$offers[] = $this->offer( $url, (int) $work['price_cents'], $currency, $work, true );
				}
			}
		}

		if ( ! empty( $work['print'] ) && is_array( $work['print'] ) ) {
			$print = $work['print'];
			$url   = Links::print_order( $work );

			if ( '' !== $url && isset( $print['price_cents'] ) && is_numeric( $print['price_cents'] ) ) {
				$print_currency = isset( $print['currency'] ) && is_string( $print['currency'] ) && '' !== $print['currency']
					? Formatting::currency( $print['currency'] )
					: $currency;

				$offers[] = $this->offer( $url, (int) $print['price_cents'], $print_currency, $work, false );
			}
		}

		return $offers;
	}

	/**
	 * One schema.org Offer.
	 *
	 * @param string $url      Attributed buy / read / order URL.
	 * @param int    $cents    Price in minor units (0 for free).
	 * @param string $currency Upper-case ISO code.
	 * @param array  $work     Normalised work (for the sale end date).
	 * @param bool   $digital  Whether the live discount applies to this offer.
	 * @return array
	 */
	private function offer( string $url, int $cents, string $currency, array $work, bool $digital ): array {
		$offer = array(
			'@type'         => 'Offer',
			'price'         => Formatting::decimal_price( $cents, $currency ),
			'priceCurrency' => $currency,
			'url'           => $url,
			'availability'  => 'https://schema.org/InStock',
			'seller'        => array(
				'@type' => 'Organization',
				'name'  => 'Publica.now',
				'url'   => Links::base(),
			),
		);

		if ( $digital && isset( $work['discount']['ends_at'] ) ) {
			$until = self::date( (string) $work['discount']['ends_at'] );
			if ( '' !== $until ) {
				$offer['priceValidUntil'] = $until;
			}
		}

		return $offer;
	}

	/**
	 * Author as a schema.org Person; links to the creator page when the
	 * author is the creator (a pen name or co-author gets no URL).
	 *
	 * @param array $work Normalised work.
	 * @return array Empty when no author name is known.
	 */
	private function person( array $work ): array {
		$name         = self::str( $work, 'author' );
		$creator_name = isset( $work['creator']['name'] ) && is_scalar( $work['creator']['name'] ) ? trim( (string) $work['creator']['name'] ) : '';

		if ( '' === $name ) {
			$name = $creator_name;
		}
		if ( '' === $name ) {
			return array();
		}

		$person = array(
			'@type' => 'Person',
			'name'  => $name,
		);

		$creator_url = isset( $work['creator']['url'] ) && is_string( $work['creator']['url'] ) ? $work['creator']['url'] : '';
		if ( '' !== $creator_url && $name === $creator_name ) {
			$person['url'] = $creator_url;
		}

		return $person;
	}

	/**
	 * ISO date (Y-m-d) from any parseable timestamp, '' otherwise.
	 *
	 * @param string $value RFC 3339 or similar.
	 * @return string
	 */
	private static function date( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? '' : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Trimmed string value of a scalar key, '' when missing or not scalar.
	 *
	 * @param array  $source Source array.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function str( array $source, string $key ): string {
		return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( (string) $source[ $key ] ) : '';
	}
}
