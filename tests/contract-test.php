<?php
/**
 * The normalisation contract test (docs/PLAN.md §9 item 8).
 *
 * Runs inside a real WordPress through WP-CLI, so it exercises the actual
 * esc_url_raw / wp_parse_url / wp_strip_all_tags behaviour rather than stubs:
 *
 *     npm run test
 *     npx wp-env run cli wp eval-file wp-content/plugins/publica-now/tests/contract-test.php
 *
 * It asserts every rule in the docs/PLAN.md §7 table against two payload
 * generations — today's production API and the Layer-1 (PR #395) shape — plus
 * the hostile-input rules the security review turned into code. No network:
 * every fixture is fed straight to Catalog::normalise_work(), which is the
 * function the whole plugin funnels through.
 *
 * Exits 0 when every assertion passes, 1 otherwise.
 *
 * @package PublicaNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! class_exists( 'PublicaNow\\Catalog' ) ) {
	echo "FAIL: the Publica.now plugin is not active on this site.\n";
	exit( 1 );
}

// `wp eval-file` includes this file inside a function, so the counters have to
// be explicitly global for publicanow_is() to see the same variables.
global $publicanow_failures, $publicanow_checks;

$publicanow_failures = 0;
$publicanow_checks   = 0;

/**
 * Assert that two values match.
 *
 * @param string $label    What is being checked.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return void
 */
function publicanow_is( $label, $expected, $actual ) {
	global $publicanow_failures, $publicanow_checks;

	++$publicanow_checks;

	if ( $expected === $actual ) {
		return;
	}

	++$publicanow_failures;

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output: this file is run by WP-CLI, never served to a browser.
	printf(
		"FAIL %s\n     expected: %s\n     actual:   %s\n",
		$label,
		wp_json_encode( $expected ),
		wp_json_encode( $actual )
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
}

$publicanow_catalog = PublicaNow\Catalog::instance();
$publicanow_base    = PublicaNow\Api_Client::instance()->base();

/*
 * ---------------------------------------------------------------------------
 * 1. Today's production payload: 12 keys, no Layer-1 fields.
 * ---------------------------------------------------------------------------
 */
$publicanow_legacy = $publicanow_catalog->normalise_work(
	array(
		'id'           => '01hx000000000000000000000a',
		'title'        => 'A Legacy Work',
		'slug'         => 'a-legacy-work',
		'content_type' => 'literary',
		'description'  => 'A description.',
		'price_cents'  => 1299,
		'currency'     => 'usd',
		'is_free'      => false,
		'cover_url'    => 'https://fls-abc.r2.cloudflarestorage.com/cover.jpg?X-Amz-Signature=deadbeef',
		'published_at' => '2026-01-01T00:00:00Z',
		'creator'      => array(
			'id'   => '01hx0000000000000000000c01',
			'name' => 'Jane Roe',
			'slug' => 'jane-roe',
		),
	)
);

publicanow_is( 'legacy: every contract key is present', 21, count( $publicanow_legacy ) );
publicanow_is( 'legacy: literary maps to the ebook kind', 'ebook', $publicanow_legacy['kind'] );
publicanow_is( 'legacy: currency is upper-cased', 'USD', $publicanow_legacy['currency'] );
publicanow_is( 'legacy: author falls back to the creator name', 'Jane Roe', $publicanow_legacy['author'] );
publicanow_is( 'legacy: list price falls back to the price', 1299, $publicanow_legacy['list_price_cents'] );
publicanow_is(
	'legacy: checkout falls back to the hosted route',
	$publicanow_base . '/checkout/a-legacy-work',
	$publicanow_legacy['checkout_url']
);
publicanow_is(
	'legacy: a signed cover is swapped for the stable route',
	$publicanow_base . '/works/01hx000000000000000000000a/cover',
	$publicanow_legacy['cover_url']
);
publicanow_is( 'legacy: print is null', null, $publicanow_legacy['print'] );
publicanow_is( 'legacy: rating is null', null, $publicanow_legacy['rating'] );
publicanow_is( 'legacy: discount is null', null, $publicanow_legacy['discount'] );
publicanow_is( 'legacy: format is null', null, $publicanow_legacy['format'] );
publicanow_is( 'legacy: language is null', null, $publicanow_legacy['language'] );
publicanow_is(
	'legacy: the work url is derived from creator + slug',
	$publicanow_base . '/creators/jane-roe/works/a-legacy-work',
	$publicanow_legacy['url']
);

/*
 * ---------------------------------------------------------------------------
 * 2. The Layer-1 payload: every new field, a live sale, an orderable print
 *    edition, and an explicit null checkout_url that must NOT be filled in.
 * ---------------------------------------------------------------------------
 */
$publicanow_layer1 = $publicanow_catalog->normalise_work(
	array(
		'id'               => '01hx000000000000000000000b',
		'title'            => 'A Layer One Work',
		'slug'             => 'a-layer-one-work',
		'content_type'     => 'print',
		'description'      => 'Another description.',
		'price_cents'      => 999,
		'list_price_cents' => 1299,
		'currency'         => 'EUR',
		'is_free'          => false,
		'discount'         => array(
			'percent_off' => 23,
			'ends_at'     => '2026-09-28T00:00:00Z',
		),
		'checkout_url'     => null,
		'print'            => array(
			'price_cents' => 2499,
			'currency'    => 'EUR',
			'order_url'   => 'https://publica.now/order/a-layer-one-work',
		),
		'author'           => 'A. Author',
		'format'           => 'EPUB',
		'language'         => 'ES',
		'rating'           => array(
			'average' => 4.55,
			'count'   => 12,
		),
		'cover_url'        => 'https://publica.now/works/01hx000000000000000000000b/cover',
		'published_at'     => '2026-02-02T00:00:00Z',
		'creator'          => array(
			'id'   => '01hx0000000000000000000c02',
			'name' => 'Jane Roe',
			'slug' => 'jane-roe',
		),
	)
);

publicanow_is( 'layer1: the effective price is kept', 999, $publicanow_layer1['price_cents'] );
publicanow_is( 'layer1: the list price is kept for the strike-through', 1299, $publicanow_layer1['list_price_cents'] );
publicanow_is( 'layer1: the discount survives', 23, $publicanow_layer1['discount']['percent_off'] );
publicanow_is( 'layer1: print price', 2499, $publicanow_layer1['print']['price_cents'] );
publicanow_is( 'layer1: print order url', 'https://publica.now/order/a-layer-one-work', $publicanow_layer1['print']['order_url'] );
publicanow_is( 'layer1: an explicit null checkout_url is NOT back-filled', null, $publicanow_layer1['checkout_url'] );
publicanow_is( 'layer1: the author is used verbatim', 'A. Author', $publicanow_layer1['author'] );
publicanow_is( 'layer1: format is lower-cased', 'epub', $publicanow_layer1['format'] );
publicanow_is( 'layer1: language is lower-cased', 'es', $publicanow_layer1['language'] );
publicanow_is( 'layer1: the rating average is rounded to one decimal', 4.6, $publicanow_layer1['rating']['average'] );
publicanow_is( 'layer1: print content_type maps to the print kind', 'print', $publicanow_layer1['kind'] );
publicanow_is( 'layer1: an unsigned same-host cover is left alone', 'https://publica.now/works/01hx000000000000000000000b/cover', $publicanow_layer1['cover_url'] );

/*
 * ---------------------------------------------------------------------------
 * 3. The rules that only exist because a payload can be wrong or hostile.
 * ---------------------------------------------------------------------------
 */
$publicanow_free = $publicanow_catalog->normalise_work(
	array(
		'id'          => '01hx000000000000000000000c',
		'slug'        => 'a-free-work',
		'title'       => 'Free',
		'is_free'     => true,
		'price_cents' => 1500,
		'creator'     => array( 'slug' => 'jane-roe' ),
	)
);

publicanow_is( 'is_free overrides a stale price column', 0, $publicanow_free['price_cents'] );
publicanow_is( 'a free work quotes no checkout', null, $publicanow_free['checkout_url'] );

$publicanow_floor = $publicanow_catalog->normalise_work(
	array(
		'id'               => '01hx000000000000000000000d',
		'slug'             => 'floor',
		'title'            => 'Floor',
		'price_cents'      => 1000,
		'list_price_cents' => 500,
		'creator'          => array( 'slug' => 'jane-roe' ),
	)
);

publicanow_is( 'a list price below the effective price is clamped', 1000, $publicanow_floor['list_price_cents'] );

$publicanow_open_sale = $publicanow_catalog->normalise_work(
	array(
		'id'       => '01hx000000000000000000000e',
		'slug'     => 'open-sale',
		'title'    => 'Open sale',
		'discount' => array( 'percent_off' => 10 ),
		'creator'  => array( 'slug' => 'jane-roe' ),
	)
);

publicanow_is( 'a discount without an end date is dropped', null, $publicanow_open_sale['discount'] );

$publicanow_hostile = $publicanow_catalog->normalise_work(
	array(
		'id'           => '01hx000000000000000000000f',
		'slug'         => 'hostile',
		'title'        => '<script>alert(1)</script>Title',
		'content_type' => 'literary',
		'cover_url'    => '//evil.example/pwn.png',
		'checkout_url' => 'javascript:alert(1)',
		'creator'      => array(
			'slug' => 'jane-roe',
			'url'  => '/wp-admin/anything',
		),
	)
);

// wp_strip_all_tags() removes a <script> element with its contents, so the
// payload disappears entirely rather than surviving as text.
publicanow_is( 'a script element is removed from the title, contents included', 'Title', $publicanow_hostile['title'] );
// A scheme-relative URL is not an absolute http(s) URL, so it is dropped and
// the card falls back to the typographic cover.
publicanow_is( 'a scheme-relative cover is rejected outright', null, $publicanow_hostile['cover_url'] );
publicanow_is(
	'a root-relative creator url is rejected and rebuilt',
	$publicanow_base . '/creators/jane-roe',
	$publicanow_hostile['creator']['url']
);

$publicanow_offhost = $publicanow_catalog->normalise_work(
	array(
		'id'        => '01hx0000000000000000000010',
		'slug'      => 'offhost',
		'title'     => 'Off-host cover',
		'cover_url' => 'https://cdn-images-1.medium.com/max/557/x.jpeg',
		'creator'   => array( 'slug' => 'jane-roe' ),
	)
);

publicanow_is(
	'a third-party cover host is replaced by the publica.now route',
	$publicanow_base . '/works/01hx0000000000000000000010/cover',
	$publicanow_offhost['cover_url']
);

/*
 * ---------------------------------------------------------------------------
 * 4. Prices: one authority for the card and the editor picker.
 * ---------------------------------------------------------------------------
 */
publicanow_is( 'JPY is zero-decimal', '¥123,456', PublicaNow\Formatting::price( 123456, 'JPY' ) );
publicanow_is( 'USD has two decimals', '$1,234.56', PublicaNow\Formatting::price( 123456, 'USD' ) );
publicanow_is(
	'the picker label agrees with the card',
	PublicaNow\Formatting::price( 13990, 'CLP' ),
	PublicaNow\Rest::price_label(
		array(
			'is_free'     => false,
			'price_cents' => 13990,
			'currency'    => 'CLP',
		)
	)
);

/*
 * ---------------------------------------------------------------------------
 * 5. Consent: a read must never create credentials.
 * ---------------------------------------------------------------------------
 */
publicanow_is(
	'Api_Client exposes no public client-registration method',
	false,
	is_callable( array( PublicaNow\Api_Client::instance(), 'register_client' ) )
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output, integers only.
printf( "\n%d checks, %d failure(s)\n", $publicanow_checks, $publicanow_failures );

exit( $publicanow_failures > 0 ? 1 : 0 );
