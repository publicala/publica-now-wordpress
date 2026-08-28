# Changelog

All notable changes to the Publica.now WordPress plugin are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The `Version:` header in `publica-now.php`, the `PUBLICANOW_VERSION` constant and
the `Stable tag` in `readme.txt` must always carry the same value; `bin/build-zip.sh`
refuses to build when they differ.

## [Unreleased]

## [1.0.0] - 2026-08-28

### Added

- Connect a publica.now creator account by pasting the profile URL or slug. The plugin
  registers its own read-only OAuth client (`catalog:read`), validates the creator and
  stores a profile snapshot. Disconnect revokes the access token at publica.now, drops
  the client, forgets the creator and purges every cached response, keeping only the
  display settings.
- "Verified website" badge when the creator's publica.now profile website matches the
  WordPress site host (informational only).
- Dynamic, server-rendered blocks with live editor previews: `publica-now/catalog`,
  `publica-now/work`, `publica-now/buy-button`.
- Shortcodes with identical attributes: `[publicanow_works]` (alias
  `[publicanow_catalog]`), `[publicanow_work]`, `[publicanow_button]`.
- Cards with cover, title, author, content-type badge, effective price with
  strike-through and sale end date, rating, optional excerpt, and Buy / Read free /
  Order paperback buttons. Grid (1–6 columns), list, single card and inline layouts.
- Attribution on every outbound link (`utm_source`, `utm_medium=wordpress_plugin`,
  `utm_campaign=publica-now-plugin`), filterable through `publicanow_link_args`.
- Two-tier transient cache (fresh, default 15 minutes; stale copy kept 7 days and
  served when the API is unreachable) plus a Refresh catalog button, which re-reads the
  profile first and purges only if publica.now answered. Never a blank block: with no
  cache at all, a link to the creator's publica.now page is rendered.
- One JSON-LD `ItemList` / `Book` / `Audiobook` / `Product` graph per page with
  `Offer`, `priceValidUntil` and `aggregateRating`; filter `publicanow_jsonld`.
- Settings → Publica.now screen, plugin action link, one-time dismissible connect
  notice, Site Health test, suggested privacy policy text, `uninstall.php`.
- Theme-overridable templates in `templates/`, overridden from
  `{theme}/publica-now/{template}.php`.
- Sandbox mode behind the `PUBLICANOW_SANDBOX` constant / `publicanow_sandbox` filter
  for development against publica.now fixtures.
- Fully translatable (text domain `publica-now`); translations are served by
  translate.wordpress.org.

### Changed

Revised during pre-submission review, before any public release. Recorded here because
each one contradicts an earlier revision of the contract in `docs/PLAN.md`.

- **Consent.** Registering an OAuth client is now reachable only from the Connect and
  Refresh catalog actions on Settings → Publica.now. `Api_Client::register_client()` is
  private and `token()` returns a `publicanow_not_connected` error instead of
  self-registering, so rendering a block or shortcode on an unconnected site — including
  an anonymous front-end page view — cannot create credentials at publica.now. Nothing
  leaves the site until an administrator clicks Connect.
- **Disconnect.** Disconnect now revokes the access token at publica.now before dropping
  the local client, and the button's description says what it actually does: revoke,
  delete the credentials and cached catalog, keep the display settings. It no longer
  implies the publica.now account itself is affected.
- **Refresh catalog** re-reads the profile before purging, and leaves both cache tiers
  untouched if the request failed. Purging first would have deleted the 7-day outage copy
  during an outage — precisely when an administrator reaches for the button.
- **Removed the `show_powered_by` setting.** The plan specified it defaulting to false;
  a credit link that ships switched off is a control nobody finds or enables, so the
  plugin renders no credit at all and the dead setting is gone rather than left in the
  options array.

[Unreleased]: https://github.com/publicala/publica-now-wordpress/compare/1.0.0...HEAD
[1.0.0]: https://github.com/publicala/publica-now-wordpress/releases/tag/1.0.0
