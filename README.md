# Publica.now for WordPress

Show a [publica.now](https://publica.now) catalog on a WordPress site and send readers to
publica.now to buy, read, listen, watch or order a printed copy. The plugin never handles
money, files or buyer data: publica.now stays the store of record (checkout, delivery,
reader, print fulfilment, payouts). On the WordPress side it is a discovery and storefront
surface (blocks and shortcodes) plus an on-ramp for creators who have a site and no
publisher yet.

- WordPress.org slug: `publica-now` (listing pending; see `docs/SUBMISSION.md`)
- Requires WordPress 6.2+, PHP 7.4+. Tested up to WordPress 7.1.
- License: GPL-2.0-or-later (`LICENSE`)
- Build contract: [`docs/PLAN.md`](docs/PLAN.md) — binding for identifiers, options, hooks and shapes
- Architecture: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Release runbook: [`docs/SUBMISSION.md`](docs/SUBMISSION.md)

## Quick start (site owners)

1. Install and activate the plugin.
2. Go to **Settings → Publica.now**, paste your publica.now profile URL
   (`https://publica.now/creators/your-slug`) or slug, click **Connect**.
3. Add the **Publica.now Catalog** block to a page, or use `[publicanow_works]`.

Nothing is sent to publica.now until you click Connect. No publica.now account yet?
Create one for free at <https://publica.now/access/creator>.

### Blocks and shortcodes

| Surface | Block | Shortcode |
| --- | --- | --- |
| Catalog (grid or list) | `publica-now/catalog` | `[publicanow_works]` (alias `[publicanow_catalog]`) |
| One work (card or inline) | `publica-now/work` | `[publicanow_work id="…"]` |
| Just the button | `publica-now/buy-button` | `[publicanow_button work="…"]` |

Attribute names are identical on both sides; the full list is in `readme.txt`
(Description) and `docs/PLAN.md` §7.

## Development

The plugin has **no build step and no runtime dependencies**: PHP, one CSS file per
context, plain JavaScript for the editor. Composer and npm only bring linting and a local
WordPress.

### Prerequisites

- PHP 7.4+ (the plugin is written for 7.4; run it on 8.x too)
- Composer 2
- Node 20+ and Docker (for `wp-env`)

### Set up

```sh
git clone https://github.com/publicala/publica-now-wordpress.git
cd publica-now-wordpress
composer install          # PHPCS + WordPress Coding Standards + PHPCompatibilityWP
npm install               # @wordpress/env
npx wp-env start          # WordPress (latest) on http://localhost:8888, admin/password
```

`wp-env` mounts the repository as the `publica-now` plugin. Log in at
`http://localhost:8888/wp-admin` (user `admin`, password `password`) and connect a real
publica.now creator on **Settings → Publica.now**; the plugin talks to production
publica.now by default.

To develop against publica.now's deterministic sandbox fixtures instead, add to
`.wp-env.override.json`:

```json
{ "config": { "PUBLICANOW_SANDBOX": true } }
```

### Coding standards

```sh
composer lint             # phpcs: WordPress + WordPress-Extra + PHPCompatibilityWP (testVersion 7.4-)
composer lint:fix         # phpcbf
composer lint:syntax      # php -l on every file
npm run lint:js           # node --check blocks/*/index.js
```

Rules live in `phpcs.xml.dist`: text domain `publica-now`, prefixes `publicanow` /
`PublicaNow` / `PUBLICANOW`, minimum WordPress 6.2.

### Plugin Check

WordPress.org runs [Plugin Check](https://wordpress.org/plugins/plugin-check/) on upload
and on every release. Run the same thing locally (installs Plugin Check into the wp-env
site first):

```sh
npm run check             # all categories
npm run check:repo        # exactly the WordPress.org upload gate (plugin_repo, severity ≥ 7)
```

### Build the distributable zip

```sh
npm run build             # = bin/build-zip.sh
# → dist/publica-now/ and dist/publica-now-1.0.0.zip
```

The script applies `.distignore`, refuses to build if the `Version:` header,
`PUBLICANOW_VERSION` and the readme `Stable tag` disagree, and fails on any dotfile or
forbidden file type in the staged plugin (both are WordPress.org upload errors).

### Listing assets

Banners and icons for the WordPress.org listing are generated, not drawn by hand:

```sh
npm run assets            # = bin/make-assets.sh (needs a Chrome-family browser)
```

Sources are `assets/images/icon.svg` and `bin/assets-src/*.html`; output goes to
`.wordpress-org/` at the exact sizes WordPress.org requires.

### Translations

Strings use the `publica-now` text domain. Regenerate the template with
`npm run pot` (runs `wp i18n make-pot` inside wp-env). Translations themselves are
made on translate.wordpress.org once the plugin is listed; no `.mo` files ship.

## Repository layout

```
publica-now.php          plugin header, constants, autoloader, bootstrap
uninstall.php            removes options and transients, revokes the API client
includes/                PublicaNow\* classes (class-{kebab}.php)
blocks/{catalog,work,buy-button}/   block.json, render.php, index.js
templates/               theme-overridable PHP templates
assets/css assets/js assets/images   frontend + admin styles, editor script, icon
languages/               publica-now.pot
.wordpress-org/          banners, icons, screenshots for the SVN assets/ directory
bin/                     build-zip.sh, make-assets.sh, assets-src/
docs/                    PLAN.md (contract), ARCHITECTURE.md, SUBMISSION.md
```

## Contributing

Open an issue or pull request on GitHub. CI runs PHPCS on PHP 7.4 and 8.3, syntax checks,
Plugin Check and the zip build on every push. Keep `docs/PLAN.md` in sync: during v1 the
document wins over the code; after v1 ships, change the document first.

## Support

- Plugin issues: <https://github.com/publicala/publica-now-wordpress/issues>
- publica.now accounts, sales, payouts and print orders: <https://publica.now/support>
