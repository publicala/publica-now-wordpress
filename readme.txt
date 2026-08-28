=== Publica.now ===
Contributors: publicala
Tags: ebook, audiobook, sell digital products, print on demand, bookstore
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show your publica.now catalog on your WordPress site and sell ebooks, audiobooks, music, video, courses and printed books. Checkout on publica.now.

== Description ==

Publica.now is a publishing and selling service for independent creators. You upload a book, an audiobook, a course, a video or a design file, and the service hosts it, runs checkout and payment processing, delivers the file, runs the in-browser reader and player, prints and ships paperbacks on demand, handles refunds and buyer support, and pays you out. This plugin is the WordPress client for that service.

Connecting is a real account link, not an embed code. When you click **Connect** the plugin registers its own read-only API client with publica.now (OAuth 2.0 client credentials, scope `catalog:read`), stores those credentials in your database and uses them for every later request. Your pages then read live data from the API: which works are published, the effective price right now including any running sale and the date it ends, whether a work is free, its formats, languages and rating, whether a print edition can be ordered, and the right hosted checkout, reader or print-order address. Change a price, start a sale or unpublish a title and your pages follow within the cache window, with nothing to edit here.

Blocks and shortcodes render that data — cover, title, author, format badge, price with the sale price struck through, rating — with a **Buy**, **Read free** or **Order paperback** button. Those buttons hand the reader to publica.now, the store of record: your site never processes a payment, stores a file or holds buyer data. Every outbound link carries this site's host name, so publica.now attributes the sale back to your website.

Not on publica.now yet? An account is free and there is no monthly fee: publica.now takes 20% plus USD 0.30 per paid sale and pays out monthly through Stripe. See [publica.now/pricing](https://publica.now/pricing) and [publica.now/access/creator](https://publica.now/access/creator).

No e-commerce runs on WordPress: no cart, checkout, downloads or buyer records, no WooCommerce, no tracking scripts, no advertising.

**Blocks and shortcodes**

Three dynamic blocks appear in the editor under *Publica.now*, each with a live preview: **Catalog** (a grid or list), **Work** (one work as a card or inline row) and **Buy Button**. The shortcodes take the same options:

    [publicanow_works limit="12" columns="3" layout="grid" order="newest"]
    [publicanow_work id="your-work-slug" layout="card"]
    [publicanow_button work="your-work-slug" format="auto"]

`[publicanow_works]` accepts `creator`, `content_type` (`literary`, `audiobook`, `music`, `video`, `course`, `zine`, `photography`, `design`, `print`), `free` (`yes|no|any`), `ids`, `exclude`, `order`, `limit` (`0` = all), `columns` (1-6), `layout` (`grid|list`), `show_excerpt`, `show_rating`, `show_author`, `show_type`, `button_text`, `class`. `[publicanow_work]` takes `id`, `layout` (`card|inline`), `show_excerpt`, `button_text`, `class`; `[publicanow_button]` takes `work`, `text`, `format`, `class`. `format` is a preference, not a filter: a work with no digital edition still shows **Order paperback**.

**Theming and hooks**

The markup inherits your theme's font and exposes CSS custom properties you can override:

    --publicanow-accent
    --publicanow-ink
    --publicanow-muted
    --publicanow-radius

Any template in `templates/` can be overridden by copying it to `{your-theme}/publica-now/`. Filters: `publicanow_work`, `publicanow_creator`, `publicanow_link_args`, `publicanow_button_label`, `publicanow_template_vars`, `publicanow_jsonld`, `publicanow_cache_ttl`. Actions: `publicanow_connected`, `publicanow_disconnected`, `publicanow_cache_purged`. Each surface outputs one schema.org JSON-LD block with an `Offer` pointing at publica.now; `add_filter( 'publicanow_jsonld', '__return_empty_array' )` disables it. The plugin sets no cookies and loads no third-party scripts; see *External Services* below, and *Settings → Privacy* for suggested policy text.

== Installation ==

You need a publica.now creator account with at least one published work; accounts are free.

1. In WordPress go to *Plugins → Add New*, search for "Publica.now", install and activate.
2. Open *Settings → Publica.now*, paste your profile URL (for example `https://publica.now/creators/your-name`) or your creator slug, and click **Connect**. The plugin registers a read-only API client and shows your name, avatar and works count.
3. Add the **Publica.now Catalog** block to a page, or put `[publicanow_works]` in your content, and publish.

Nothing is sent to publica.now until you click **Connect**. **Disconnect**, on the same screen, asks publica.now to revoke this site's access token, then deletes the API credentials and the cached catalog, keeping only your display settings.

== Frequently Asked Questions ==

= Does the plugin charge anything? =

No. The plugin is free and GPL. publica.now charges 20% plus USD 0.30 per paid sale and no monthly fee: https://publica.now/pricing

= Where do readers pay, and who supports them? =

On publica.now. The Buy button opens that work's checkout page, where the reader signs in with an email code and pays by card; your site never sees card or account details. Refunds, downloads and print orders go to publica.now support under its terms of service.

= Can I sell printed books, and what about free works? =

Works with a published print edition show an **Order paperback** button linking to the print order page; publica.now's print-on-demand partner handles printing and shipping, and you set the price in your dashboard. Free works show a **Read free** button that opens the work in the publica.now reader or player, and a free work with a print edition shows both.

= Does it work without a publica.now account? =

No. It only displays a publica.now catalog. Accounts are free: https://publica.now/access/creator

= Can I show another creator's catalog? =

Yes, once your own account is connected. The `creator` attribute accepts any public creator slug, so a magazine can feature an author it publishes. The sale is attributed to your site, but the money goes to the work's owner.

= How often is the catalog refreshed? =

Every 15 minutes by default (**Cache duration**, or the `publicanow_cache_ttl` filter). If publica.now cannot be reached the plugin keeps serving the last copy for up to seven days; with none at all it shows a quiet link to your profile, never a broken block. **Refresh catalog** re-reads your catalog and replaces the cache only if publica.now answers.

= Does it work on multisite? =

Yes. Each site connects its own account; settings, cache and credentials are per site. Network activation connects nothing.

== Screenshots ==

1. Settings → Publica.now before connecting: paste your profile URL.
2. Connected: your profile photo, name, works count, profile link and the Refresh catalog / Disconnect actions, plus the prompt to list this site as your publica.now website and earn the "Verified website" badge.
3. The Catalog block in the editor, with its live preview and options.
4. The catalog on the front end: covers, prices, badges and Buy buttons in a three-column grid.
5. A single work placed on a page: cover, type badge, title, author, excerpt, the ebook price with the paperback price beside it, and Buy next to Order paperback.
6. The shortcode cheat sheet on the settings screen.

== Changelog ==

= 1.0.0 =
* Initial release: connect a publica.now creator account; Catalog / Work / Buy Button blocks and shortcodes; attribution on every outbound link; cached catalog with a seven-day outage fallback; JSON-LD structured data; overridable templates; Site Health test; suggested privacy text; full uninstall.

== Upgrade Notice ==

= 1.0.0 =
First release.

== External Services ==

This plugin connects to **publica.now** (https://publica.now), a publishing and selling service for independent creators operated by Publica.la. It does not work without a publica.now account and makes no request until you connect one.

**What is sent, and when**

* **When you click Connect** on *Settings → Publica.now*: the creator slug you entered and your site's host name, naming the read-only API client the plugin registers. publica.now returns credentials, stored in your database and used for every later request. This is the only moment credentials are created, and it always takes an administrator.
* **When a page with a block or shortcode renders and the cache is empty or stale**, and **when an administrator clicks Refresh catalog**: the plugin requests your public catalog (works, prices, availability, cover addresses) with those credentials. Only the creator slug and standard HTTP metadata (your server's IP address, user agent) are sent; no visitor data.
* **When a visitor views a page**: their browser loads cover images from publica.now, which therefore receives their IP address and browser details as with any image. Clicking Buy, Read free or Order paperback takes them to publica.now; the link carries your site's host name as `utm_source`.
* **When you click Disconnect**: the plugin asks publica.now to revoke this site's access token, then deletes the stored API credentials, the cached catalog and your stored profile. Deleting the plugin removes all of that from your site too, but makes no request: deactivation has already discarded the access token, and an unused token expires at publica.now within the hour.

**What is received:** public catalog data (titles, authors, descriptions, prices, sale dates, formats, ratings, cover images) and your public profile (name, avatar, website, works count) — all already visible to anyone on publica.now. The plugin sets no cookies, loads no scripts or styles from publica.now, and sends analytics nowhere.

* Terms of service: https://publica.now/terms
* Privacy policy: https://publica.now/privacy
