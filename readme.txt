=== Publica.now – Sell Ebooks, Audiobooks, Video & Print Books ===
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

Publica.now connects your WordPress site to your **publica.now** creator account. It reads your public catalog through the publica.now API and renders it on your pages with blocks or shortcodes: cover, title, author, format badge, price (with sale price and end date while a sale runs), rating, and a **Buy**, **Read free** or **Order paperback** button that sends the reader to publica.now to complete the purchase.

The plugin is a storefront surface for a service, not a shop of its own. publica.now remains the store of record: it hosts checkout and payment processing, delivers files, runs the reader and player, manufactures and ships printed copies, handles refunds and pays creators out. Nothing about a sale ever touches your WordPress database.

**Who it is for**

* **Creators already selling on publica.now.** Paste your profile URL once, then place the *Publica.now Catalog* block on any page. Every click carries your site's host name so sales are attributed to your website in your publica.now dashboard.
* **Creators with a WordPress site and no publisher yet.** publica.now lets you sell PDF and EPUB ebooks, audiobooks, music, video, courses, zines, photography, design files and print-on-demand paperbacks with no monthly fee. Create a free account at [publica.now/access/creator](https://publica.now/access/creator), upload a work, then connect this plugin.

**How money flows**

Readers pay on publica.now, not on your site. publica.now charges 20% plus USD 0.30 per paid sale and no monthly fee; free works cost nothing to distribute. Balances are paid out to the creator monthly through Stripe. See [publica.now/pricing](https://publica.now/pricing) for current rates and the print-on-demand cost model.

**What the plugin does not do**

* No payments, carts, checkout or downloads on WordPress; no WooCommerce or other e-commerce plugin required.
* No buyer accounts, emails or purchase records are stored on your site.
* No files are copied to your server; covers are served by publica.now.
* No tracking scripts, analytics or "Powered by" links unless you opt in.

**Blocks**

Three dynamic blocks are available in the editor under *Publica.now*; each has a live preview and the same options as its shortcode:

* **Publica.now Catalog** (`publica-now/catalog`) — a grid or list of your works.
* **Publica.now Work** (`publica-now/work`) — one work as a card or an inline row.
* **Publica.now Buy Button** (`publica-now/buy-button`) — just the button.

**Shortcodes**

`[publicanow_works]` (alias `[publicanow_catalog]`) — your catalog. Attributes:

* `creator` — a publica.now creator slug; defaults to the connected account.
* `content_type` — one of `literary`, `audiobook`, `music`, `video`, `course`, `zine`, `photography`, `design`, `print`.
* `free` — `yes`, `no` or `any` (default `any`).
* `ids` / `exclude` — comma-separated work ids or slugs to include or leave out.
* `order` — `newest` (default), `oldest`, `title`, `price_asc`, `price_desc`.
* `limit` — number of works (default `12`, `0` shows all).
* `columns` — `1` to `6` (grid only).
* `layout` — `grid` (default) or `list`.
* `show_excerpt`, `show_rating`, `show_author`, `show_type` — `yes` or `no`.
* `button_text` — override the primary button label.
* `class` — extra CSS class on the wrapper.

`[publicanow_work id="..."]` — one work. Attributes: `id` (id or slug, required), `layout` (`card` or `inline`), `show_excerpt`, `button_text`, `class`.

`[publicanow_button work="..."]` — one button. Attributes: `work` (id or slug, required), `text`, `format` (`digital`, `print` or `auto`, default `auto`), `class`.

Example: `[publicanow_works content_type="audiobook" columns="4" order="title"]`

**Theming and hooks**

The markup inherits your theme's font and uses CSS custom properties (`--publicanow-accent`, `--publicanow-ink`, `--publicanow-muted`, `--publicanow-radius`) that you can override in your stylesheet. Every template in `templates/` can be replaced by copying it to `{your-theme}/publica-now/{template}.php`. Filters: `publicanow_work`, `publicanow_creator`, `publicanow_link_args`, `publicanow_button_label`, `publicanow_template_vars`, `publicanow_jsonld`, `publicanow_cache_ttl`. Actions: `publicanow_connected`, `publicanow_disconnected`, `publicanow_cache_purged`. Each rendered surface also outputs one schema.org `ItemList` / `Book` / `Audiobook` / `Product` JSON-LD block with an `Offer` pointing at publica.now; disable it with `add_filter( 'publicanow_jsonld', '__return_empty_array' )`.

**Privacy**

The plugin sets no cookies and loads no third-party scripts. Visitors' browsers load cover images from publica.now, and the outbound buttons carry your site's host name as a referral parameter. Details are in the *External Services* section below, and suggested text is added to *Settings → Privacy* for your privacy policy.

Documentation: [publica.now/wordpress](https://publica.now/wordpress). Source code: [github.com/publicala/publica-now-wordpress](https://github.com/publicala/publica-now-wordpress).

== Installation ==

You need a publica.now creator account with at least one published work. Accounts are free: [publica.now/access/creator](https://publica.now/access/creator).

1. In WordPress go to *Plugins → Add New*, search for "Publica.now", install and activate. Or upload the zip from *Plugins → Add New → Upload Plugin*.
2. Open *Settings → Publica.now*.
3. Paste your publica.now profile URL (for example `https://publica.now/creators/your-name`) or just your creator slug, and click **Connect**. The plugin registers a read-only API client with publica.now and shows your name, avatar and number of works.
4. Add the **Publica.now Catalog** block to a page, or put `[publicanow_works]` in your content. Publish.

Nothing is sent to publica.now until you click **Connect**. Use **Disconnect** on the same screen to revoke the API client and delete everything the plugin stored.

== Frequently Asked Questions ==

= Does the plugin charge anything? =

No. The plugin is free and GPL. publica.now charges 20% plus USD 0.30 per paid sale, no monthly fee, and pays creators out monthly. Current rates: https://publica.now/pricing

= Where do readers pay? =

On publica.now. The Buy button opens the checkout page for that work on publica.now, where the reader signs in with an email code and pays by card. Your site never sees card or account details.

= Can I sell printed books? =

Yes. Works with a published print edition show an **Order paperback** button that links to the print order page on publica.now. Printing and shipping are handled by publica.now's print-on-demand partner; you set the price above the manufacturing cost in your publica.now dashboard.

= What about free works? =

Free works show a **Read free** button that opens the work on publica.now, where the reader can read or listen in the browser. A free work with a print edition shows both buttons.

= Who handles refunds and support for buyers? =

publica.now. Buyers contact publica.now support for refunds, download problems and print orders, under the publica.now terms of service.

= Does it work without a publica.now account? =

No. The plugin only displays a publica.now catalog. Creating an account is free: https://publica.now/access/creator

= Can I show another creator's catalog? =

Yes. The `creator` attribute accepts any public creator slug, so a magazine could feature an author it publishes. Sales are still attributed to your site's host name, but the money goes to the creator who owns the work.

= How often is the catalog refreshed? =

Every 15 minutes by default (the **Cache duration** setting or the `publicanow_cache_ttl` filter changes it). If publica.now cannot be reached, the plugin keeps showing the last copy for up to seven days; if there is no copy at all it shows a quiet link to your publica.now profile instead of a broken block. The **Refresh catalog** button on the settings screen clears the cache immediately.

= Does it work on multisite? =

Yes. Each site connects its own publica.now account; settings, cache and API credentials are per site. Network activation activates the plugin on every site without connecting any of them.

= Can I change the design or the markup? =

The stylesheet uses CSS custom properties you can override, the wrapper accepts a `class` attribute, and every template can be copied to `{your-theme}/publica-now/` and edited. Button labels go through the `publicanow_button_label` filter.

= Do I need WooCommerce? =

No. The plugin does not depend on WooCommerce or any other plugin.

= Which content types are supported? =

Ebooks (PDF, EPUB), audiobooks, music, video, courses, zines, photography, design files and print-on-demand paperbacks — whatever publica.now can sell. Each card shows a badge with the type.

== Screenshots ==

1. Settings → Publica.now before connecting: paste your publica.now profile URL.
2. Connected: your name, number of works, a link to your profile and the Refresh catalog / Disconnect actions. A "Verified website" badge appears when your publica.now website matches this site.
3. The Publica.now Catalog block in the editor with its live preview and options.
4. The catalog on the front end: covers, prices, format badges and Buy buttons in a three-column grid.
5. A single work with a sale price and the Order paperback button.
6. The shortcode cheat sheet on the settings screen.

== Changelog ==

= 1.0.0 =
* Initial release: connect a publica.now creator account, Catalog / Work / Buy Button blocks, `[publicanow_works]`, `[publicanow_work]` and `[publicanow_button]` shortcodes, attribution on every outbound link, cached catalog with stale fallback, JSON-LD structured data, overridable templates, Site Health test, privacy policy text, full uninstall.

== Upgrade Notice ==

= 1.0.0 =
First release.

== External Services ==

This plugin connects to **publica.now** (https://publica.now), a creator storefront service operated by Publica.la. It does not work without a publica.now account and does nothing until you connect one.

**What is sent, and when**

* **When you click Connect** on *Settings → Publica.now*: the creator slug you entered and your site's host name (used as the name of the read-only API client the plugin registers). publica.now returns API credentials, which the plugin stores in your database and uses for every later request.
* **When a page with a block or shortcode renders and the cache is empty or older than the cache duration**, and **when an administrator clicks Refresh catalog**: the plugin requests your public catalog (works, prices, availability, cover image addresses) from publica.now with those credentials. Only the creator slug and standard HTTP metadata (your server's IP address, user agent) are sent. No visitor data is sent.
* **When a visitor views a page**: their browser loads cover images directly from publica.now, so publica.now receives the visitor's IP address and browser details as with any image. When they click Buy, Read free or Order paperback they leave your site for publica.now; the link carries your site's host name as `utm_source` so the sale is attributed to your website.
* **When you click Disconnect** or delete the plugin: the plugin asks publica.now to revoke the API client and removes everything it stored.

**What is received:** public catalog data (titles, authors, descriptions, prices, sale dates, formats, ratings, cover images) and your public profile (name, avatar, website, number of works). Everything the plugin reads is already visible to anyone on publica.now.

The plugin sets no cookies, loads no scripts or styles from publica.now, and does not send analytics anywhere.

* Terms of service: https://publica.now/terms
* Privacy policy: https://publica.now/privacy
