# Publica.now for WordPress — v1 plan and build contract

Status: v1 in build (2026-08-28). This document is the contract every part of the build follows. If code and this document disagree during v1, the document wins; after v1 ships, change the document first.

## 1. What it is, in one paragraph

A free, GPL WordPress plugin, listed on WordPress.org as `publica-now`, that lets a creator who already sells on publica.now show their catalog on their own WordPress site and send buyers to publica.now to pay, read, listen, watch or order a printed copy. The plugin never handles money, files, or buyer data: publica.now stays the store of record (checkout, delivery, reader, print fulfilment, payouts). On the WordPress side it is a **discovery and storefront surface** — blocks and shortcodes that render works with a cover, price, and a "Buy / Read free / Order paperback" button — and a **funnel** into publica.now for creators who have a WordPress site and no publisher yet.

Two jobs, one plugin:

1. **For creators already on publica.now** — connect in one step (paste your publica.now profile URL), then drop the *Publica.now Catalog* block on any page. Sales are attributed back to the site.
2. **For creators not yet on publica.now** — the plugin listing on WordPress.org (searchable for "sell ebook", "sell audiobook", "print on demand", "sell PDF") and the plugin's own settings screen explain what publica.now does and link to `publica.now/access/creator`. WordPress.org is the discovery channel; the plugin is the on-ramp.

## 2. What already exists on publica.now (verified against `origin/main`, 2026-08-28)

- **Public read API** `GET /api/v1/public/works?creator={slug}&content_type=&free=&limit=1..100&cursor=`; `GET /api/v1/public/works/{id|slug}`; `GET /api/v1/public/creators/{slug|id}`; `POST /api/v1/public/batch`. All need a bearer token with scope `catalog:read`.
- **Self-serve OAuth**, no human: `POST /oauth/register` (RFC 7591, `{"client_name","scope"}` → `client_id`/`client_secret`), `POST /oauth/token` (client_credentials, 1-hour opaque token, no refresh), `POST /oauth/revoke`. Throttles: 10 registrations/hour/IP, 60 token calls/min/IP. Every scope is read-only over data the storefront serves anonymously (`App\Support\AgentScopes`).
- **Sandbox**: same routes under `/api/v1/sandbox` or header `X-Publica-Now-Sandbox: true`; deterministic fixtures (`App\Support\AgentSandbox`).
- **Checkout is hosted**: `GET /checkout/{work-slug}` (digital, paid, refuses print works and free works), `GET /order/{work-slug}` (printed copy; needs a published print edition with product_key, page_count, price > 0 and cost > 0 — `PrintOrderService::orderable()`; behind `feature:print`, on in prod), free works are read from the work page `/creators/{creator}/works/{work}`. Buyers sign in on publica.now (email OTP).
- **Attribution**: `App\Http\Middleware\CaptureAttribution` stores `ref`, `utm_source`, `utm_medium`, `utm_campaign`, ad click ids and referrer host in a long-lived cookie on any GET; `PurchaseAttribution` carries `ref`/`utm_*` into Stripe metadata and back onto the `purchases` row. So a link into publica.now with `utm_*` is attributed end to end with no server change.
- **Stable public images**: `/works/{id}/cover`, `/creators/{id}/avatar` (route-based, no expiry).
- **What the public work payload lacks today** (`App\Support\PublicCatalogPresenter::work()`): `cover_url` is the 1-hour signed storage URL (known defect), `price_cents` reads the raw column with `defaultEdition`/`activeDiscount` unloaded (known defect — sales invisible, edition-priced works stale), no `checkout_url`, no print information, no author, no format, no rating. `creator()` lacks `avatar_url` and `website`. **This is layer 1 below.**

## 3. Layers (Pablo's rule: independent, mergeable on their own)

### Layer 1 — publicanow PR: "public catalog: what a storefront needs" (branch `feat/public-catalog-storefront-fields`, worktree `~/publicanow-wp-api`)

Extends `PublicWork` and `PublicCreator` — additive only, no field removed or renamed — and fixes the two known defects. Applies to the JSON read, the batch endpoint, the export, and the sandbox fixtures (they share the presenter, and the export/batch paths must eager-load the same relations).

`PublicWork` after this PR:

| field | type | rule |
| --- | --- | --- |
| `cover_url` | uri\|null | `Work::publicCoverUrl()` — stable route, never the signed URL |
| `price_cents` | int\|null | **effective** price now: `Work::price()->currentCents`, `0` when free |
| `list_price_cents` | int\|null | `Work::price()->baseCents`; equals `price_cents` when not on sale |
| `discount` | object\|null | `{ "percent_off": int, "ends_at": RFC3339 }` while a sale is live, else null |
| `checkout_url` | uri\|null | `route('checkout', slug)` when published, paid, not print; else null |
| `print` | object\|null | `{ "price_cents", "currency", "order_url": route('orders.create', slug) }` when `PrintOrderService::orderable($work)` and `config('features.print')`; else null |
| `author` | string | `Work::authorName() ?: creator->name` |
| `format` | string\|null | `defaultEdition->format ?? work->format` (epub, pdf, mp3…), lowercase |
| `language` | string\|null | `WorkLanguage::stored($work)` |
| `rating` | object\|null | `{ "average": float (1 decimal), "count": int }` only when `rating_count > 0` |

`PublicCreator` after this PR: `avatar_url` (uri\|null — the public route, same contract as covers), `website` (uri\|null — already rendered on the public creator page and in its schema.org `sameAs`).

Also: controller/batch/export eager-load `creator:id,name,slug`, `defaultEdition`, `activeDiscount`, `printEdition`; `AgentSandbox` fixtures carry every new field (one fixture with a live discount, one with `print`); OpenAPI `resources/openapi/publica-now.json` schemas + `info.version` 1.1.0 → 1.2.0 + changelog note in `/api-policy.md` if that document lists versions; Pest tests in `tests/Feature/Api/PublicReadApiTest.php` for every rule in the table (discounted price, stale-column edition price, print null when not orderable, checkout null for free/print, cover is the route); `pint`, `phpstan`, `rector --dry-run` pass. `content_type` stays the stored value (`literary`, `audiobook`, `music`, `video`, `course`, `zine`, `photography`, `design`, `print`) — the OpenAPI example currently says "ebook"; fix the example, not the data.

### Layer 2 — the plugin (this repo, `publicala/publica-now-wordpress`)

Complete v1, below. Works against **today's** production API too: every new Layer-1 field is optional on read, with fallbacks (`checkout_url` ← `{base}/checkout/{slug}` for paid non-print; `print` absent → no print button; `author` ← `creator.name`).

### Layer 3 — publicanow PR: "WordPress on publica.now" (branch `feat/wordpress-plugin-surface`, after layer 2 stabilises)

- Marketing page `/wordpress` (trilingual en/es/pt, editorial tier): what the plugin does, install steps, the two blocks, the shortcode, screenshots, FAQ (fees unchanged, no buyer data on WP, print on demand), CTA to WordPress.org listing (or the GitHub release zip until the listing is approved) and to `/access/creator`.
- `/devs#embeds` gets a "WordPress plugin" subsection; `llms.txt`, `AGENTS.md`, `skills/publica-now/SKILL.md` list `/wordpress` and the plugin repo.
- Dashboard `/dashboard/works/{id}/share`: a "Sell it on your WordPress site" card with `[publicanow_work id="…"]` prefilled + link to `/wordpress`.
- Not in v1: a "WordPress" entry in the orank/agent manifests beyond the links above; a dedicated per-site verification flow (see §5).

## 4. Plugin scope — v1 is complete, not minimal

**In**

- Connect by pasting a publica.now profile URL or slug. Plugin registers its own OAuth client, mints tokens, validates the creator, stores a profile snapshot. Disconnect + purge.
- "Verified website" badge when the creator's publica.now profile `website` matches the WordPress `home_url()` host — informational, never a gate (public catalog data is public).
- Blocks (dynamic, server-rendered, `block.json` + `render.php`, no build step): `publica-now/catalog`, `publica-now/work`, `publica-now/buy-button`. Editor previews via `ServerSideRender`; the work picker reads from the plugin's own REST route.
- Shortcodes with the same attributes: `[publicanow_works]`, `[publicanow_work]`, `[publicanow_button]`.
- Cards: cover (stable URL, lazy, `alt`), title, author, content-type badge (Ebook / Audiobook / Music / Video / Course / Zine / Photography / Design / Print), price with strike-through + "ends {date}" on sale, rating (when present), excerpt (optional), one or two buttons: **Buy** (digital) / **Read free** / **Order paperback** (print). Print-only works show only the print button. Layouts: grid (1–6 columns), list, single card, inline button.
- Attribution on every outbound link: `utm_source={site host}`, `utm_medium=wordpress_plugin`, `utm_campaign=publica-now-plugin`, filterable. `rel="noopener"`; optional new tab.
- Caching: per-request transient (default 15 min, filterable), plus a 7-day stale copy served when the API fails, plus a "Refresh catalog" button. Token cached ~55 min. No cron.
- Failure behaviour: never a blank block. API down + no cache → a quiet link to the creator's publica.now page; admins see the reason inline.
- SEO/GEO: one JSON-LD `ItemList` (or single item) per rendered surface — `Book`/`Audiobook`/`Product` with `Offer` pointing at publica.now, `priceValidUntil` on sale, `aggregateRating` when present.
- Admin: Settings → Publica.now; plugin action link; dismissible "connect" notice after activation; Site Health test (API reachable, connected, cache state); privacy policy suggested text; `uninstall.php` removes options and transients and revokes the token.
- Sandbox toggle (hidden behind `PUBLICANOW_SANDBOX` constant / filter) for development against fixtures.
- i18n-ready (text domain `publica-now`, all strings translatable, `.pot` generated). WordPress.org's GlotPress handles translations, so no `.mo` shipped.
- Theme overridable templates (`templates/*.php`, override in `{theme}/publica-now/`).
- Accessibility: buttons are links with visible text; badges have text; focus styles; `prefers-reduced-motion`; colour contrast ≥ 4.5:1 in light and dark.

**Out (deliberately)**

- Payments, carts, checkout, or downloads on WordPress. WooCommerce integration. Buyer accounts/SSO on WordPress. Webhooks into WordPress. Publishing works from WordPress (write scopes do not exist self-serve, by design). Widgets (legacy) — blocks cover it. Showing *other* creators' catalogs as an affiliate (the connect flow binds one creator; the shortcode `creator=` attribute can still name another slug, which is public data — documented, not promoted).

## 5. Security and privacy model

- The plugin fetches only from `https://publica.now` (constant, filterable for sandbox/staging; the filter is not a setting). No user-supplied URL is ever fetched. Creator slug validated `^[a-z0-9][a-z0-9-]{0,189}$`.
- OAuth client credentials in `publicanow_oauth` option (autoload off). Read-only scope. A leaked secret can only read public data faster.
- No buyer PII exists on the WordPress side. What leaves the site: the creator slug, the WordPress site host (in `client_name` at registration and in `utm_source` on links), and normal HTTP request metadata. Disclosed in `readme.txt` → "External services" (a WordPress.org requirement).
- All admin actions: capability `manage_options`, nonces, REST permission callbacks. All output escaped at the point of output (`esc_html`, `esc_url`, `esc_attr`, `wp_kses_post` for descriptions). No inline event handlers. No external scripts/styles (WordPress.org forbids CDNs); covers are content images from the service, which is allowed.
- Ownership verification is informational only (website match). A real proof-of-ownership (dashboard-issued site token) is a Layer-3+ item if it ever matters; nothing in v1 needs it because nothing in v1 can act on the creator's behalf.

## 6. Repository layout and file ownership (build teams)

```
publica-now/                       ← the plugin (repo root IS the plugin dir)
  publica-now.php                  A  header, constants, autoloader, bootstrap
  uninstall.php                    A
  readme.txt                       C  WordPress.org readme
  LICENSE                          C  GPL-2.0-or-later
  README.md CHANGELOG.md           C  GitHub-facing
  includes/
    class-plugin.php               A  singleton, hook registration, activation/deactivation
    class-api-client.php           A  HTTP, OAuth register/token/revoke, sandbox, errors
    class-cache.php                A  transients (fresh + stale), keys, purge
    class-catalog.php              A  typed reads + normalisation (the ONE shape below)
    class-settings.php             A  Settings API page, connect/disconnect, notices, action link
    class-rest.php                 A  REST: connect, purge, works (editor picker)
    class-site-health.php          A
    class-privacy.php              A
    class-links.php                B  buy/read/print URL builders + attribution
    class-renderer.php             B  loads templates, escapes, enqueues CSS on render
    class-shortcodes.php           B
    class-blocks.php               B  register_block_type ×3 from blocks/*/block.json
    class-structured-data.php      B  JSON-LD collector + footer output
    class-formatting.php           B  price/currency/date/badge label helpers
  blocks/{catalog,work,buy-button}/block.json render.php index.js   B
  templates/works-grid.php works-list.php work-card.php work-inline.php buy-button.php empty.php   B
  assets/css/publica-now.css assets/css/admin.css assets/js/admin.js   A(admin) B(frontend)
  assets/images/icon.svg           C
  languages/publica-now.pot        C (generated)
  .wordpress-org/ banner-772x250.png banner-1544x500.png icon-128x128.png icon-256x256.png screenshot-N.png   C
  .github/workflows/ci.yml deploy.yml   C
  .wp-env.json composer.json phpcs.xml.dist .distignore .editorconfig .gitignore   C
  docs/PLAN.md (this) docs/SUBMISSION.md docs/ARCHITECTURE.md   C
```

Team A = core & admin, Team B = rendering & blocks, Team C = packaging, docs, assets, CI, submission. Teams share only the contracts in §7. An integration pass wires and QA's the whole in `wp-env`.

## 7. Contracts (shared by all teams — do not deviate)

**Identifiers.** Slug/text domain `publica-now`. Namespace `PublicaNow` (classes in `includes/class-*.php`, PSR-4 name → `class-{kebab}.php`). Global prefix `publicanow_` for functions, options, transients, hooks, REST namespace `publica-now/v1`, CSS `.publicanow-`, block namespace `publica-now/`. Constants `PUBLICANOW_VERSION`, `PUBLICANOW_FILE`, `PUBLICANOW_PATH`, `PUBLICANOW_URL`, `PUBLICANOW_API_BASE` (`https://publica.now`), `PUBLICANOW_MIN_PHP` `7.4`, `PUBLICANOW_MIN_WP` `6.2`. Code must run on PHP 7.4 (no enums, readonly, match, named args, `str_contains` without polyfill — WordPress ships polyfills for `str_contains`/`str_starts_with` since 5.9, fine to use).

**Options.**
- `publicanow_settings` (array, autoload yes): `creator_slug` (string), `open_in_new_tab` (bool, default false), `show_powered_by` (bool, default **false** — WordPress.org forbids opt-out credits), `cache_ttl` (int seconds, default 900), `default_columns` (int 3), `default_layout` (`grid`|`list`), `show_excerpt` (bool true), `show_rating` (bool true), `button_text` (string, empty = default).
- `publicanow_oauth` (array, autoload no): `client_id`, `client_secret`, `registered_at`, `scope`.
- `publicanow_creator` (array, autoload yes): the last-validated `PublicCreator` payload + `fetched_at` + `website_matches` (bool).
- `publicanow_activated_at` (int) — drives the one-time connect notice.
- Transients: `publicanow_token` (access token; TTL = `expires_in` − 300), `publicanow_c_{md5}` fresh cache, `publicanow_s_{md5}` stale (7 days). Purge = delete all `publicanow_c_*`/`publicanow_s_*` via `$wpdb` LIKE on `_transient_publicanow_%` (and `_transient_timeout_`), plus object-cache group flush hook.

**Catalog API (Team A provides, Team B consumes).**
```php
namespace PublicaNow;
final class Catalog {
  public static function instance(): Catalog;
  /** @return array|\WP_Error normalised creator (see below) */
  public function creator( string $slug, bool $bypass_cache = false );
  /** @param array $args { creator?:string, content_type?:string, free?:bool|null, ids?:string[], exclude?:string[], order?:'newest'|'oldest'|'title'|'price_asc'|'price_desc', limit?:int (1..100, default 12), offset?:int }
   *  @return array{items: array<int,array>, total:int, source:'api'|'cache'|'stale'}|\WP_Error   ALL works of the creator are fetched (cursor-paginated, max 5 pages) and cached once; filtering/sorting/limiting happens in PHP. */
  public function works( array $args = array() );
  /** @return array|\WP_Error normalised work */
  public function work( string $id_or_slug, bool $bypass_cache = false );
  public function connected_slug(): string;   // '' when not connected
}
```
Normalised **work** array (every key always present; nulls allowed where marked):
```
id, title, slug, content_type (stored value), kind (ebook|audiobook|music|video|course|zine|photography|design|print|other — from content_type: literary→ebook),
description|null, author, creator {id,name,slug,url}, url, cover_url|null,
is_free (bool), price_cents (int|null, effective), list_price_cents (int|null), currency (upper, 'USD' default),
discount {percent_off:int, ends_at:string}|null, checkout_url|null, print {price_cents,currency,order_url}|null,
rating {average:float,count:int}|null, format|null, language|null, published_at|null
```
Fallbacks when the API predates Layer 1: `checkout_url` = `{base}/checkout/{slug}` if `!is_free && kind!=='print'`; `list_price_cents` = `price_cents`; `author` = `creator.name`; `print`, `rating`, `format`, `language`, `discount` = null; `kind` derived. Filter `publicanow_work` runs last on the normalised array.

Normalised **creator**: `id, name, slug, url, bio|null, works_count, avatar_url|null, website|null, accepts_support`.

**Links (Team B).** `Links::buy(array $work): string` → `checkout_url` with attribution; `Links::read($work)` → `url` with attribution; `Links::print_order($work)` → `print.order_url` with attribution; `Links::creator($creator)`. Attribution args: `utm_source` = `wp_parse_url( home_url(), PHP_URL_HOST )`, `utm_medium` = `wordpress_plugin`, `utm_campaign` = `publica-now-plugin`; filter `publicanow_link_args( array $args, array $work, string $kind )`. Button choice: `print`-kind → print only; `is_free` → Read free (+ print if any); paid → Buy (+ Order paperback if `print`).

**Shortcode / block attributes (identical names).**
- catalog: `creator` (slug, default connected), `content_type`, `free` (`yes|no|any`, default any), `ids`, `exclude`, `order` (default newest), `limit` (default 12, 0 = all), `columns` (1–6), `layout` (`grid|list`), `show_excerpt`, `show_rating`, `show_author`, `show_type`, `button_text`, `class`.
- work: `id` (id or slug, required), `layout` (`card|inline`), `show_excerpt`, `button_text`, `class`.
- buy-button: `work` (id or slug, required), `text`, `format` (`digital|print|auto`, default auto), `class`.
Block attribute types: strings for everything except `limit`/`columns` (number) and `show_*` (boolean). Block `render.php` receives `$attributes` and calls `Renderer::instance()->catalog( $attributes )` / `->work(...)` / `->button(...)`, which return HTML strings.

**Renderer (Team B).** `Renderer::catalog(array $atts): string`, `work(array $atts): string`, `button(array $atts): string`. Enqueues `publica-now` style on first render (`wp_enqueue_style` inside render is fine on the front end; also hook `enqueue_block_assets`). Templates loaded through `Renderer::template( string $name, array $vars )` with theme override `locate_template( 'publica-now/' . $name . '.php' )`. Every template escapes its own output. Wrapper element gets `class="publicanow publicanow-{surface} {class}"` and `data-publicanow-creator`.

**Structured data (Team B).** `Structured_Data::instance()->add( array $work )` during render; single `wp_footer` (priority 20) output of one JSON-LD `<script>` per page, `@type: ItemList` when >1. Filter `publicanow_jsonld( array $graph )`. Disable with `add_filter('publicanow_jsonld', '__return_empty_array')`.

**REST (Team A).** Namespace `publica-now/v1`: `POST /connect {creator}` (manage_options) → `{creator, website_matches}`; `POST /disconnect`; `POST /purge`; `GET /works?search=&content_type=` (edit_posts) → `{items:[{id,title,slug,kind,cover_url,price_label}]}`; `GET /status` (edit_posts) → `{connected, creator_slug, creator_name}`. Nonce via `wp_rest`, localised into `publicanowAdmin` / `publicanowEditor`.

**Hooks.** Filters: `publicanow_api_base`, `publicanow_cache_ttl`, `publicanow_work`, `publicanow_creator`, `publicanow_link_args`, `publicanow_jsonld`, `publicanow_button_label( string $label, string $kind, array $work )`, `publicanow_template_vars( array $vars, string $template )`. Actions: `publicanow_connected( array $creator )`, `publicanow_disconnected( string $slug )`, `publicanow_cache_purged`.

**Errors.** Team A returns `WP_Error` with codes `publicanow_http`, `publicanow_oauth`, `publicanow_not_found`, `publicanow_rate_limited`, `publicanow_invalid_slug`, `publicanow_not_connected`; `->get_error_data()` carries `status` and `retry_after` when known. Team B renders `templates/empty.php` for any `WP_Error` (admins with `manage_options` see the message).

**Design tokens (frontend CSS).** Accent `#E05A2B` (coral; dark `#FF6B35`), ink `#1A1816`, paper `#FAFAF7`, muted `#6B6560`. CSS variables `--publicanow-accent`, `--publicanow-ink`, `--publicanow-muted`, `--publicanow-radius: 12px`, `--publicanow-cover-shadow`. Inherit the theme's font family. Covers 2:3 with a soft "book" shadow. Restraint: coral only on the primary button and sale price.

## 8. WordPress.org requirements we build to (checked 2026-08-28)

- Current WordPress **7.1** → `Tested up to: 7.1`; `Requires at least: 6.2`; `Requires PHP: 7.4`; `Stable tag` = plugin version = `PUBLICANOW_VERSION` = `1.0.0`; `License: GPLv2 or later` + `License URI`.
- `readme.txt` sections: description (≤150-char short description first), Installation, FAQ, Screenshots (numbered, matching `.wordpress-org/screenshot-N.png`), Changelog, Upgrade Notice, **External services** (service, what data, when, ToS + privacy links — mandatory disclosure).
- Guidelines: GPL-compatible; no obfuscation; no external asset loading (bundle everything; service content images excepted); no "powered by" without opt-in; no tracking without consent (we have none); prefix everything; sanitize/escape/nonce; use `wp_remote_*`; no `error_log` spam; no PHP short tags; no writing outside uploads; complete on submission (no "coming soon").
- Reviewers run **Plugin Check** — we run `wp plugin check publica-now` in `wp-env` and PHPCS `WordPress` + `WordPress-Extra` + `PHPCompatibilityWP` (7.4+) in CI; both must be clean.
- Assets for the SVN `assets/` dir: `banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png`, `icon-256x256.png` (or `icon.svg`), `screenshot-1..N.png`.
- Submission is a human step: wordpress.org account → *Add Your Plugin* → upload zip → review (typically days to a few weeks; one plugin per account in review at a time) → SVN URL → commit `trunk/`, `tags/1.0.0/`, `assets/`. `docs/SUBMISSION.md` carries the exact form answers and the SVN commands; `deploy.yml` uses `10up/action-wordpress-plugin-deploy` on tags once SVN credentials exist.

## 9. Acceptance (v1 done means all of these)

1. Fresh `wp-env` site: activate → notice → paste `https://publica.now/creators/{slug}` → connected card shows name, avatar, works count (against production API).
2. Catalog block inserted from the editor shows a live preview; published page renders grid with covers, prices, badges, and buttons whose `href` carries the three `utm_*` args to `publica.now`.
3. `[publicanow_work id="…"]` and `[publicanow_button work="…"]` render; a print-orderable work shows "Order paperback" → `/order/{slug}`; a free work shows "Read free".
4. Kill network → page still renders from stale cache; purge + kill → renders the fallback link, no PHP notices.
5. `wp plugin check` clean; PHPCS clean; PHP 7.4 lint clean; no console errors in editor.
6. JSON-LD validates (schema.org validator or `structured-data-testing`); one script per page.
7. `readme.txt` validates on the WordPress.org readme validator; zip built by `.distignore` installs from *Plugins → Add New → Upload*.
8. Layer 1 PR open on publicanow with green local gates; plugin verified against both today's API and the Layer-1 branch's payload (fixture test).
9. `docs/SUBMISSION.md` complete; GitHub repo pushed; screenshots + banners in `.wordpress-org/`.
