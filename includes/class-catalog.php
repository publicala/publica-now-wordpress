<?php
/**
 * Typed catalog reads and normalisation: the ONE work/creator shape every
 * template, block and JSON-LD builder consumes.
 *
 * @package PublicaNow
 */

namespace PublicaNow;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads creators and works through Api_Client + Cache and normalises the
 * payload to the contract shape, with fallbacks for an API that predates
 * the Layer-1 storefront fields.
 */
final class Catalog {

	/**
	 * Creator slug rule from the contract.
	 */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,189}$/';

	/**
	 * Work id or slug: ULIDs, sandbox ids ("wrk_sandbox_…") and slugs.
	 */
	const ID_PATTERN = '/^[A-Za-z0-9_-]{1,190}$/';

	/**
	 * Works per page when crawling a creator (the API maximum).
	 */
	const PAGE_SIZE = 100;

	/**
	 * Hard stop for the crawl: 500 works per creator is far beyond any real
	 * publica.now catalog today and bounds the worst-case request count.
	 */
	const MAX_PAGES = 5;

	/**
	 * Default number of works returned by works().
	 */
	const DEFAULT_LIMIT = 12;

	/**
	 * Accepted sort orders.
	 */
	const ORDERS = array( 'newest', 'oldest', 'title', 'price_asc', 'price_desc' );

	/**
	 * Stored content_type → kind.
	 */
	const KINDS = array(
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

	/**
	 * Singleton instance.
	 *
	 * @var Catalog|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Catalog
	 */
	public static function instance(): Catalog {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The connected creator's slug, '' when not connected.
	 *
	 * @return string
	 */
	public function connected_slug(): string {
		$slug = (string) Settings::get( 'creator_slug', '' );

		return self::is_valid_slug( $slug ) ? $slug : '';
	}

	/**
	 * Whether a string is a valid creator slug.
	 *
	 * @param string $slug Candidate.
	 * @return bool
	 */
	public static function is_valid_slug( string $slug ): bool {
		return 1 === preg_match( self::SLUG_PATTERN, $slug );
	}

	/**
	 * Turn whatever the user pasted into a slug: a bare slug, or a profile
	 * URL such as https://publica.now/creators/{slug}, with or without a
	 * locale prefix (/es/, /pt/), trailing slash, query string or a deeper
	 * path (…/works/{work}).
	 *
	 * @param string $input Raw input.
	 * @return string The slug, or '' when nothing valid could be extracted.
	 */
	public static function normalise_slug( string $input ): string {
		$input = trim( $input );

		if ( '' === $input ) {
			return '';
		}

		$candidate = $input;

		$looks_like_url = false !== strpos( $input, '/' ) || false !== stripos( $input, 'publica.now' );

		if ( $looks_like_url ) {
			$url = $input;
			if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
				// "publica.now/creators/x" without a scheme.
				$url = 'https://' . ltrim( $url, '/' );
			}

			$path = wp_parse_url( $url, PHP_URL_PATH );
			$path = is_string( $path ) ? trim( $path, '/' ) : '';

			$segments = '' === $path ? array() : explode( '/', $path );

			// Drop a leading locale segment (en, es, pt, pt-br…).
			if ( ! empty( $segments ) && preg_match( '/^[a-z]{2}(-[a-z]{2})?$/i', $segments[0] ) ) {
				array_shift( $segments );
			}

			$index = array_search( 'creators', $segments, true );

			if ( false !== $index ) {
				// "/creators/" with nothing after it names no one.
				if ( ! isset( $segments[ $index + 1 ] ) ) {
					return '';
				}
				$candidate = $segments[ $index + 1 ];
			} elseif ( 1 === count( $segments ) ) {
				// "publica.now/{slug}" — not an official URL, but unambiguous.
				$candidate = $segments[0];
			} else {
				return '';
			}
		}

		$candidate = strtolower( trim( rawurldecode( $candidate ) ) );
		$candidate = ltrim( $candidate, '@' );

		return self::is_valid_slug( $candidate ) ? $candidate : '';
	}

	/**
	 * Map a stored content_type to the kind Team B renders.
	 *
	 * @param string|null $content_type Stored value.
	 * @return string
	 */
	public static function kind_from_content_type( $content_type ): string {
		$content_type = is_string( $content_type ) ? strtolower( trim( $content_type ) ) : '';

		return isset( self::KINDS[ $content_type ] ) ? self::KINDS[ $content_type ] : 'other';
	}

	/**
	 * Read a creator.
	 *
	 * @param string $slug         Creator slug or profile URL.
	 * @param bool   $bypass_cache Skip the fresh cache (the result is still stored).
	 * @return array|WP_Error Normalised creator.
	 */
	public function creator( string $slug, bool $bypass_cache = false ) {
		$slug = self::normalise_slug( $slug );

		if ( '' === $slug ) {
			return $this->invalid_slug_error();
		}

		$name = 'creator:' . $slug;

		if ( ! $bypass_cache ) {
			$cached = Cache::get( $name );
			if ( is_array( $cached ) ) {
				return $this->finish_creator( $cached );
			}
		}

		$response = Api_Client::instance()->get( '/api/v1/public/creators/' . rawurlencode( $slug ) );

		if ( is_wp_error( $response ) ) {
			// A 404 is authoritative; anything else may be served stale.
			if ( 'publicanow_not_found' !== $response->get_error_code() ) {
				$stale = Cache::get_stale( $name );
				if ( is_array( $stale ) ) {
					return $this->finish_creator( $stale );
				}
			}

			return $response;
		}

		$raw = $this->unwrap( $response );

		if ( empty( $raw['slug'] ) && empty( $raw['id'] ) ) {
			return $this->unexpected_error();
		}

		$creator = $this->normalise_creator( $raw );
		Cache::set( $name, $creator );

		return $this->finish_creator( $creator );
	}

	/**
	 * Read works. ALL works of the creator are fetched once (cursor-paginated,
	 * at most 5 pages of 100) and cached; filtering, sorting and paging happen
	 * in PHP so any combination of block attributes is served from one entry.
	 *
	 * Accepted $args keys:
	 * - creator      (string)    Slug; default the connected creator.
	 * - content_type (string)    Stored content type or kind.
	 * - free         (bool|null) true = free only, false = paid only, null = both.
	 * - ids          (string[])  Only these ids/slugs (array or comma list).
	 * - exclude      (string[])  Never these ids/slugs.
	 * - order        (string)    newest|oldest|title|price_asc|price_desc.
	 * - limit        (int)       1..100, default 12; 0 or less = all.
	 * - offset       (int)       Skip this many after filtering/sorting.
	 *
	 * @param array $args Query arguments, see above.
	 * @return array|WP_Error {items: array[], total: int, source: 'api'|'cache'|'stale', error: WP_Error|null, truncated: bool}
	 */
	public function works( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'creator'      => '',
				'content_type' => '',
				'free'         => null,
				'ids'          => array(),
				'exclude'      => array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- "exclude" is a block attribute, not a WP_Query parameter.
				'order'        => 'newest',
				'limit'        => self::DEFAULT_LIMIT,
				'offset'       => 0,
			)
		);

		/*
		 * Nothing reads the API before an administrator has connected an
		 * account — not even with an explicit creator attribute. Registering
		 * the OAuth client belongs to the connect flow, so an unconnected site
		 * must fall through to the empty state instead of contacting
		 * publica.now from a (possibly anonymous) page render.
		 */
		if ( '' === $this->connected_slug() ) {
			return $this->not_connected_error();
		}

		$creator_input = trim( (string) $args['creator'] );
		$slug          = '' === $creator_input ? $this->connected_slug() : self::normalise_slug( $creator_input );

		if ( '' === $slug ) {
			return $this->invalid_slug_error();
		}

		$all = $this->all_works( $slug );

		if ( is_wp_error( $all ) ) {
			return $all;
		}

		$items = $all['items'];

		$content_type = strtolower( trim( (string) $args['content_type'] ) );
		if ( '' !== $content_type && 'any' !== $content_type ) {
			$items = array_filter(
				$items,
				static function ( $work ) use ( $content_type ) {
					return $work['content_type'] === $content_type || $work['kind'] === $content_type;
				}
			);
		}

		$free = self::parse_free( $args['free'] );
		if ( null !== $free ) {
			$items = array_filter(
				$items,
				static function ( $work ) use ( $free ) {
					return $work['is_free'] === $free;
				}
			);
		}

		$ids = self::parse_list( $args['ids'] );
		if ( ! empty( $ids ) ) {
			$items = array_filter(
				$items,
				static function ( $work ) use ( $ids ) {
					return in_array( strtolower( $work['id'] ), $ids, true ) || in_array( strtolower( $work['slug'] ), $ids, true );
				}
			);
		}

		$exclude = self::parse_list( $args['exclude'] );
		if ( ! empty( $exclude ) ) {
			$items = array_filter(
				$items,
				static function ( $work ) use ( $exclude ) {
					return ! in_array( strtolower( $work['id'] ), $exclude, true ) && ! in_array( strtolower( $work['slug'] ), $exclude, true );
				}
			);
		}

		$items = array_values( $items );
		$this->sort( $items, (string) $args['order'] );

		$total  = count( $items );
		$offset = max( 0, (int) $args['offset'] );
		$limit  = (int) $args['limit'];

		if ( $limit > 0 ) {
			$items = array_slice( $items, $offset, min( 100, $limit ) );
		} elseif ( $offset > 0 ) {
			$items = array_slice( $items, $offset );
		}

		return array(
			'items'     => array_map( array( $this, 'finish_work' ), $items ),
			'total'     => $total,
			'source'    => $all['source'],
			'error'     => $all['error'],
			// The crawl stops at MAX_PAGES × PAGE_SIZE. Landing exactly on the
			// cap means the creator's catalog is at or beyond it and what we
			// hold is very probably partial; admins are told so.
			'truncated' => count( $all['items'] ) >= ( self::MAX_PAGES * self::PAGE_SIZE ),
		);
	}

	/**
	 * Read a single work by id or slug.
	 *
	 * @param string $id_or_slug Work id or slug.
	 * @param bool   $bypass_cache Skip the fresh cache.
	 * @return array|WP_Error Normalised work.
	 */
	public function work( string $id_or_slug, bool $bypass_cache = false ) {
		$key = trim( $id_or_slug );

		if ( '' === $key || ! preg_match( self::ID_PATTERN, $key ) ) {
			return new WP_Error(
				'publicanow_invalid_slug',
				__( 'That is not a valid publica.now work id or slug.', 'publica-now' ),
				array( 'status' => 400 )
			);
		}

		// Same rule as works(): no API contact before an admin has connected.
		if ( '' === $this->connected_slug() ) {
			return $this->not_connected_error();
		}

		$lookup = strtolower( $key );
		$name   = 'work:' . $lookup;

		if ( ! $bypass_cache ) {
			// Most single-work blocks belong to the connected creator, whose
			// whole catalog is usually already cached: no request needed.
			$slug = $this->connected_slug();
			if ( '' !== $slug ) {
				$found = $this->find_in_list( Cache::get( 'works:' . $slug ), $lookup );
				if ( null !== $found ) {
					return $this->finish_work( $found );
				}
			}

			$cached = Cache::get( $name );
			if ( is_array( $cached ) ) {
				return $this->finish_work( $cached );
			}
		}

		/*
		 * A recent failure is replayed instead of repeated: during an outage
		 * every visitor would otherwise pay their own 10-second blocking
		 * request, and the site would keep hammering an API that is already
		 * refusing it.
		 */
		$response = $bypass_cache ? null : Cache::get_failure( $name );

		if ( null === $response ) {
			$response = Api_Client::instance()->get( '/api/v1/public/works/' . rawurlencode( $key ) );

			if ( is_wp_error( $response ) ) {
				Cache::set_failure( $name, $response );
			}
		}

		if ( is_wp_error( $response ) ) {
			if ( 'publicanow_not_found' !== $response->get_error_code() ) {
				$stale = Cache::get_stale( $name );
				if ( is_array( $stale ) ) {
					return $this->finish_work( $stale );
				}

				// The connected creator's 7-day catalog copy is the last resort.
				$slug = $this->connected_slug();
				if ( '' !== $slug ) {
					$found = $this->find_in_list( Cache::get_stale( 'works:' . $slug ), $lookup );
					if ( null !== $found ) {
						return $this->finish_work( $found );
					}
				}
			}

			return $response;
		}

		$raw = $this->unwrap( $response );

		if ( empty( $raw['id'] ) ) {
			return $this->unexpected_error();
		}

		$work = $this->normalise_work( $raw );
		Cache::set( $name, $work );
		Cache::clear_failure( $name );

		return $this->finish_work( $work );
	}

	/**
	 * Find a work by lowercase id or slug inside a cached list.
	 *
	 * @param mixed  $items  Cached list (or null/false when absent).
	 * @param string $lookup Lowercase id or slug.
	 * @return array|null
	 */
	private function find_in_list( $items, string $lookup ) {
		if ( ! is_array( $items ) ) {
			return null;
		}

		foreach ( $items as $work ) {
			if ( is_array( $work ) && ( strtolower( (string) $work['id'] ) === $lookup || strtolower( (string) $work['slug'] ) === $lookup ) ) {
				return $work;
			}
		}

		return null;
	}

	/**
	 * Every work of one creator, from cache, API, or the stale copy.
	 *
	 * @param string $slug         Valid creator slug.
	 * @param bool   $bypass_cache Skip the fresh cache.
	 * @return array|WP_Error {items: array[], source: 'api'|'cache'|'stale', error: WP_Error|null}
	 */
	public function all_works( string $slug, bool $bypass_cache = false ) {
		if ( ! self::is_valid_slug( $slug ) ) {
			return $this->invalid_slug_error();
		}

		$name = 'works:' . $slug;

		if ( ! $bypass_cache ) {
			$cached = Cache::get( $name );
			if ( is_array( $cached ) ) {
				return array(
					'items'  => $cached,
					'source' => 'cache',
					'error'  => null,
				);
			}
		}

		// See work(): an outage is remembered for a minute rather than re-tried
		// on every single page view.
		$fetched = $bypass_cache ? null : Cache::get_failure( $name );

		if ( null === $fetched ) {
			$fetched = $this->fetch_all_works( $slug );

			if ( is_wp_error( $fetched ) ) {
				Cache::set_failure( $name, $fetched );
			}
		}

		if ( ! is_wp_error( $fetched ) ) {
			Cache::set( $name, $fetched );
			Cache::clear_failure( $name );

			return array(
				'items'  => $fetched,
				'source' => 'api',
				'error'  => null,
			);
		}

		$stale = Cache::get_stale( $name );

		if ( is_array( $stale ) ) {
			return array(
				'items'  => $stale,
				'source' => 'stale',
				'error'  => $fetched,
			);
		}

		return $fetched;
	}

	/**
	 * Crawl the creator's works through cursor pagination.
	 *
	 * @param string $slug Creator slug.
	 * @return array|WP_Error Normalised works.
	 */
	private function fetch_all_works( string $slug ) {
		$client = Api_Client::instance();
		$items  = array();
		$cursor = '';

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array(
				'creator' => $slug,
				'limit'   => self::PAGE_SIZE,
			);

			if ( '' !== $cursor ) {
				$query['cursor'] = $cursor;
			}

			$response = $client->get( '/api/v1/public/works', $query );

			if ( is_wp_error( $response ) ) {
				// A partial crawl must not be cached as the whole catalog.
				return $response;
			}

			$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

			foreach ( $data as $raw ) {
				if ( ! is_array( $raw ) || empty( $raw['id'] ) ) {
					continue;
				}

				/*
				 * Belt and braces: the sandbox ignores the creator filter and
				 * returns every fixture, and a catalog must never show another
				 * creator's works. A row without a creator is kept as-is.
				 */
				if ( isset( $raw['creator']['slug'] ) && is_string( $raw['creator']['slug'] ) && strtolower( $raw['creator']['slug'] ) !== $slug ) {
					continue;
				}

				$items[] = $this->normalise_work( $raw );
			}

			$cursor = $this->next_cursor( $response );

			if ( '' === $cursor || count( $data ) < self::PAGE_SIZE ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * The next cursor from the response envelope: meta.next_cursor, or the
	 * cursor query arg of meta.next. Never the URL itself — the plugin only
	 * ever requests paths it builds.
	 *
	 * @param array $response Decoded envelope.
	 * @return string '' when there is no next page.
	 */
	private function next_cursor( array $response ): string {
		$meta = isset( $response['meta'] ) && is_array( $response['meta'] ) ? $response['meta'] : array();

		if ( ! empty( $meta['next_cursor'] ) && is_string( $meta['next_cursor'] ) ) {
			return $meta['next_cursor'];
		}

		if ( ! empty( $meta['next'] ) && is_string( $meta['next'] ) ) {
			$query = wp_parse_url( $meta['next'], PHP_URL_QUERY );
			if ( is_string( $query ) ) {
				$params = array();
				wp_parse_str( $query, $params );
				if ( ! empty( $params['cursor'] ) && is_string( $params['cursor'] ) ) {
					return $params['cursor'];
				}
			}
		}

		return '';
	}

	/**
	 * Normalise a raw PublicWork to the contract shape. Every key is always
	 * present. Works against today's API and the Layer-1 payload alike.
	 *
	 * @param array $raw Raw API object.
	 * @return array
	 */
	public function normalise_work( array $raw ): array {
		$base = Api_Client::instance()->base();

		$id    = self::text( isset( $raw['id'] ) ? $raw['id'] : '' );
		$slug  = self::text( isset( $raw['slug'] ) ? $raw['slug'] : '' );
		$title = self::text( isset( $raw['title'] ) ? $raw['title'] : '' );

		$content_type = isset( $raw['content_type'] ) && is_string( $raw['content_type'] ) ? strtolower( trim( $raw['content_type'] ) ) : '';
		$kind         = self::kind_from_content_type( $content_type );

		$raw_creator  = isset( $raw['creator'] ) && is_array( $raw['creator'] ) ? $raw['creator'] : array();
		$creator_slug = self::text( isset( $raw_creator['slug'] ) ? $raw_creator['slug'] : '' );
		$creator_name = self::text( isset( $raw_creator['name'] ) ? $raw_creator['name'] : '' );
		$creator_url  = self::uri( isset( $raw_creator['url'] ) ? $raw_creator['url'] : null );

		if ( null === $creator_url && '' !== $creator_slug ) {
			$creator_url = $base . '/creators/' . rawurlencode( $creator_slug );
		}

		$creator = array(
			'id'   => self::text( isset( $raw_creator['id'] ) ? $raw_creator['id'] : '' ),
			'name' => $creator_name,
			'slug' => $creator_slug,
			'url'  => null === $creator_url ? $base : $creator_url,
		);

		$url = self::uri( isset( $raw['url'] ) ? $raw['url'] : null );
		if ( null === $url ) {
			$url = ( '' !== $creator_slug && '' !== $slug )
				? $base . '/creators/' . rawurlencode( $creator_slug ) . '/works/' . rawurlencode( $slug )
				: $base;
		}

		$price_cents = self::int_or_null( isset( $raw['price_cents'] ) ? $raw['price_cents'] : null );
		$is_free     = array_key_exists( 'is_free', $raw ) ? (bool) $raw['is_free'] : ( 0 === $price_cents );

		if ( $is_free ) {
			// is_free is authoritative; a free work never quotes a price.
			$price_cents = 0;
		}

		$list_price_cents = self::int_or_null( isset( $raw['list_price_cents'] ) ? $raw['list_price_cents'] : null );
		if ( null === $list_price_cents ) {
			$list_price_cents = $price_cents;
		} elseif ( null !== $price_cents && $list_price_cents < $price_cents ) {
			// Never strike through a "list" price lower than what the buyer pays.
			$list_price_cents = $price_cents;
		}

		$currency = self::currency( isset( $raw['currency'] ) ? $raw['currency'] : null );

		$discount = null;
		if ( isset( $raw['discount'] ) && is_array( $raw['discount'] ) ) {
			$percent = isset( $raw['discount']['percent_off'] ) ? (int) $raw['discount']['percent_off'] : 0;
			$ends_at = self::datetime( isset( $raw['discount']['ends_at'] ) ? $raw['discount']['ends_at'] : null );
			if ( $percent > 0 && null !== $ends_at ) {
				$discount = array(
					'percent_off' => $percent,
					'ends_at'     => $ends_at,
				);
			}
		}

		$print = null;
		if ( isset( $raw['print'] ) && is_array( $raw['print'] ) ) {
			$order_url = self::uri( isset( $raw['print']['order_url'] ) ? $raw['print']['order_url'] : null );
			if ( null !== $order_url ) {
				$print = array(
					'price_cents' => self::int_or_null( isset( $raw['print']['price_cents'] ) ? $raw['print']['price_cents'] : null ),
					'currency'    => self::currency( isset( $raw['print']['currency'] ) ? $raw['print']['currency'] : $currency ),
					'order_url'   => $order_url,
				);
			}
		}

		$checkout_url = self::uri( isset( $raw['checkout_url'] ) ? $raw['checkout_url'] : null );
		if ( null === $checkout_url && ! array_key_exists( 'checkout_url', $raw ) && ! $is_free && 'print' !== $kind && '' !== $slug ) {
			// Pre-Layer-1 API: the hosted checkout route is stable and public.
			$checkout_url = $base . '/checkout/' . rawurlencode( $slug );
		}

		$rating = null;
		if ( isset( $raw['rating'] ) && is_array( $raw['rating'] ) ) {
			$count = isset( $raw['rating']['count'] ) ? (int) $raw['rating']['count'] : 0;
			if ( $count > 0 && isset( $raw['rating']['average'] ) && is_numeric( $raw['rating']['average'] ) ) {
				$rating = array(
					'average' => round( (float) $raw['rating']['average'], 1 ),
					'count'   => $count,
				);
			}
		}

		$author = self::text( isset( $raw['author'] ) ? $raw['author'] : '' );
		if ( '' === $author ) {
			$author = $creator_name;
		}

		$description = isset( $raw['description'] ) && is_string( $raw['description'] ) ? trim( $raw['description'] ) : '';

		return array(
			'id'               => $id,
			'title'            => $title,
			'slug'             => $slug,
			'content_type'     => $content_type,
			'kind'             => $kind,
			'description'      => '' === $description ? null : $description,
			'author'           => $author,
			'creator'          => $creator,
			'url'              => $url,
			'cover_url'        => self::cover_url( isset( $raw['cover_url'] ) ? $raw['cover_url'] : null, $id, $base ),
			'is_free'          => $is_free,
			'price_cents'      => $price_cents,
			'list_price_cents' => $list_price_cents,
			'currency'         => $currency,
			'discount'         => $discount,
			'checkout_url'     => $checkout_url,
			'print'            => $print,
			'rating'           => $rating,
			'format'           => self::lower_or_null( isset( $raw['format'] ) ? $raw['format'] : null ),
			'language'         => self::lower_or_null( isset( $raw['language'] ) ? $raw['language'] : null ),
			'published_at'     => self::datetime( isset( $raw['published_at'] ) ? $raw['published_at'] : null ),
		);
	}

	/**
	 * Normalise a raw PublicCreator to the contract shape.
	 *
	 * @param array $raw Raw API object.
	 * @return array
	 */
	public function normalise_creator( array $raw ): array {
		$base = Api_Client::instance()->base();
		$slug = self::text( isset( $raw['slug'] ) ? $raw['slug'] : '' );

		$url = self::uri( isset( $raw['url'] ) ? $raw['url'] : null );
		if ( null === $url ) {
			$url = '' !== $slug ? $base . '/creators/' . rawurlencode( $slug ) : $base;
		}

		$bio = isset( $raw['bio'] ) && is_string( $raw['bio'] ) ? trim( $raw['bio'] ) : '';

		return array(
			'id'              => self::text( isset( $raw['id'] ) ? $raw['id'] : '' ),
			'name'            => self::text( isset( $raw['name'] ) ? $raw['name'] : '' ),
			'slug'            => $slug,
			'url'             => $url,
			'bio'             => '' === $bio ? null : $bio,
			'works_count'     => isset( $raw['works_count'] ) ? max( 0, (int) $raw['works_count'] ) : 0,
			'avatar_url'      => self::uri( isset( $raw['avatar_url'] ) ? $raw['avatar_url'] : null ),
			'website'         => self::uri( isset( $raw['website'] ) ? $raw['website'] : null ),
			'accepts_support' => ! empty( $raw['accepts_support'] ),
		);
	}

	/**
	 * Apply the publicanow_work filter. Runs at read time, never before
	 * caching, so filters may depend on request context.
	 *
	 * @param array $work Normalised work.
	 * @return array
	 */
	public function finish_work( array $work ): array {
		/**
		 * Filters a normalised work right before it is handed to a renderer.
		 *
		 * @param array $work Normalised work.
		 */
		$filtered = apply_filters( 'publicanow_work', $work );

		return is_array( $filtered ) ? $filtered : $work;
	}

	/**
	 * Apply the publicanow_creator filter.
	 *
	 * @param array $creator Normalised creator.
	 * @return array
	 */
	public function finish_creator( array $creator ): array {
		/**
		 * Filters a normalised creator right before it is used.
		 *
		 * @param array $creator Normalised creator.
		 */
		$filtered = apply_filters( 'publicanow_creator', $creator );

		return is_array( $filtered ) ? $filtered : $creator;
	}

	/**
	 * Sort works in place.
	 *
	 * @param array  $items Normalised works.
	 * @param string $order One of ORDERS.
	 * @return void
	 */
	private function sort( array &$items, string $order ) {
		$order = in_array( $order, self::ORDERS, true ) ? $order : 'newest';

		switch ( $order ) {
			case 'oldest':
				usort(
					$items,
					static function ( $a, $b ) {
						return strcmp( (string) $a['published_at'], (string) $b['published_at'] );
					}
				);
				break;

			case 'title':
				usort(
					$items,
					static function ( $a, $b ) {
						return strnatcasecmp( $a['title'], $b['title'] );
					}
				);
				break;

			case 'price_asc':
			case 'price_desc':
				$direction = 'price_asc' === $order ? 1 : -1;
				usort(
					$items,
					static function ( $a, $b ) use ( $direction ) {
						// Unpriced works go last in either direction.
						if ( null === $a['price_cents'] ) {
							return null === $b['price_cents'] ? 0 : 1;
						}
						if ( null === $b['price_cents'] ) {
							return -1;
						}

						return $direction * ( $a['price_cents'] <=> $b['price_cents'] );
					}
				);
				break;

			case 'newest':
			default:
				usort(
					$items,
					static function ( $a, $b ) {
						return strcmp( (string) $b['published_at'], (string) $a['published_at'] );
					}
				);
				break;
		}
	}

	/**
	 * Interpret the "free" argument: bool, null, or the shortcode words.
	 *
	 * @param mixed $value Raw value.
	 * @return bool|null
	 */
	public static function parse_free( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( null === $value ) {
			return null;
		}

		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( 'yes', 'true', '1', 'free' ), true ) ) {
			return true;
		}

		if ( in_array( $value, array( 'no', 'false', '0', 'paid' ), true ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Comma list or array → lowercase, trimmed, non-empty strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function parse_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			$item = strtolower( trim( (string) $item ) );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Unwrap a `{data: {...}}` envelope (single reads) or return as-is.
	 *
	 * @param array $response Decoded response.
	 * @return array
	 */
	private function unwrap( array $response ): array {
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}

		return $response;
	}

	/**
	 * The stable cover URL: always publica.now's own `/works/{id}/cover` route
	 * whenever the API hands back a cover stored anywhere else.
	 *
	 * Two reasons, both real. Signed storage URLs expire within the hour, which
	 * is useless inside a 15-minute/7-day cache. And a creator-supplied cover on
	 * a third-party CDN would make every visitor's browser call a host neither
	 * readme.txt's External Services section nor the suggested privacy text
	 * names — an undisclosed third party. The route covers both: it never
	 * expires and it is served by publica.now.
	 *
	 * @param mixed  $raw  Raw cover_url.
	 * @param string $id   Work id.
	 * @param string $base API base.
	 * @return string|null
	 */
	private static function cover_url( $raw, string $id, string $base ) {
		$cover = self::uri( $raw );

		if ( null === $cover ) {
			return null;
		}

		if ( '' === $id ) {
			return self::same_host( $cover, $base ) ? $cover : null;
		}

		if ( ! self::same_host( $cover, $base ) ) {
			return $base . '/works/' . rawurlencode( $id ) . '/cover';
		}

		$query = wp_parse_url( $cover, PHP_URL_QUERY );

		if ( is_string( $query ) && preg_match( '/(^|&)(X-Amz-[A-Za-z-]+|Signature|Expires|token)=/i', $query ) ) {
			return $base . '/works/' . rawurlencode( $id ) . '/cover';
		}

		return $cover;
	}

	/**
	 * Whether a URL is served by the same host as the API base.
	 *
	 * @param string $url  Absolute URL.
	 * @param string $base API base.
	 * @return bool
	 */
	private static function same_host( string $url, string $base ): bool {
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$base_host = wp_parse_url( $base, PHP_URL_HOST );

		if ( ! is_string( $url_host ) || ! is_string( $base_host ) ) {
			return false;
		}

		return strtolower( $url_host ) === strtolower( $base_host );
	}

	/**
	 * Plain text: tags stripped, whitespace trimmed.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function text( $value ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		return is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';
	}

	/**
	 * An absolute http(s) URL or null.
	 *
	 * Sanitising with esc_url_raw() kills every script-executing scheme but
	 * leaves scheme-relative ("//host/x"), root-relative ("/wp-admin/…"),
	 * query-only and fragment-only values untouched, so the explicit https?://
	 * test below is what actually makes the return value the absolute URL the
	 * callers (and readme.txt) claim it is.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function uri( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$url = esc_url_raw( trim( $value ), array( 'http', 'https' ) );

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? $url : null;
	}

	/**
	 * Integer or null (never a coerced 0 for garbage).
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function int_or_null( $value ) {
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Lowercase string or null.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function lower_or_null( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return strtolower( trim( wp_strip_all_tags( $value ) ) );
	}

	/**
	 * Upper-case ISO 4217 code, USD by default.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function currency( $value ): string {
		if ( is_string( $value ) && preg_match( '/^[A-Za-z]{3}$/', trim( $value ) ) ) {
			return strtoupper( trim( $value ) );
		}

		return 'USD';
	}

	/**
	 * A parseable date-time string, or null.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function datetime( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$value = trim( $value );

		return false === strtotime( $value ) ? null : $value;
	}

	/**
	 * Shared "no account has been connected yet" error.
	 *
	 * @return WP_Error
	 */
	private function not_connected_error(): WP_Error {
		return new WP_Error(
			'publicanow_not_connected',
			__( 'Connect your publica.now account in Settings → Publica.now.', 'publica-now' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Shared invalid-slug error.
	 *
	 * @return WP_Error
	 */
	private function invalid_slug_error(): WP_Error {
		return new WP_Error(
			'publicanow_invalid_slug',
			__( 'That is not a valid publica.now creator slug or profile URL.', 'publica-now' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Shared "payload did not look like what we expected" error.
	 *
	 * @return WP_Error
	 */
	private function unexpected_error(): WP_Error {
		return new WP_Error(
			'publicanow_http',
			__( 'Publica.now returned a response the plugin could not read.', 'publica-now' ),
			array( 'status' => 502 )
		);
	}
}
