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
  stores a profile snapshot; Disconnect revokes the client and purges everything.
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
  served when the API is unreachable) plus a Refresh catalog button. Never a blank
  block: with no cache at all, a link to the creator's publica.now page is rendered.
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

[Unreleased]: https://github.com/publicala/publica-now-wordpress/compare/1.0.0...HEAD
[1.0.0]: https://github.com/publicala/publica-now-wordpress/releases/tag/1.0.0
