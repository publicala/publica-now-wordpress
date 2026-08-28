# Submitting Publica.now to the WordPress.org plugin directory — runbook

This is the human step of the release: nothing in it is automated until the very end
(SVN deploys via GitHub Actions after approval). Follow it top to bottom. Every rule quoted
here was verified against WordPress.org's handbook, the upload form's source code and
Plugin Check 2.1.0 on 2026-08-28; where a rule has changed since, the linked page wins.

Target listing: `https://wordpress.org/plugins/publica-now/` — slug **`publica-now`**,
display name **Publica.now – Sell Ebooks, Audiobooks, Video & Print Books**, version
**1.0.0**.

---

## 0. Before anything else — the account

WordPress.org ties the plugin, the SVN repository and the trademark exception to the
**account that uploads**. Decide it first; it cannot be changed later without a support
request.

1. **Use `publicala`**, the username in `readme.txt` → `Contributors: publicala`.
   - Check it exists: https://profiles.wordpress.org/publicala/ . If it returns 404,
     register it at https://login.wordpress.org/register **with an `@publica.la`
     mailbox** (see 3). If someone else owns that username, pick another one (for
     example `publicanow`) and change the `Contributors:` line in `readme.txt` before
     building — a contributor that is not a real WordPress.org username is a Plugin
     Check error, and the profile link on the listing would be dead.
   - Pablo's personal account works too, but then `Contributors:` must list *that*
     username and the ownership checkbox in the form ("I am using a WordPress.org account
     that accurately represents the plugin owner") is answered as a company employee.
2. **Enable two-factor authentication** on the account
   (https://profiles.wordpress.org/me/profile/edit/group/3/ → Two-Factor). The upload
   form refuses to load without it (mandatory since 2024-10-01).
3. **Company e-mail address.** The plugin name and slug *start with the brand*
   (Guideline 17). WordPress.org only accepts that from a verified owner, and ownership
   is checked mostly through the e-mail domain of the submitter. Set the account e-mail
   to `…@publica.la` (or `…@publica.now` if such a mailbox exists — closer to the brand).
   Be ready to prove that Publica.la operates publica.now if a reviewer asks: the
   publica.now Terms of Service (https://publica.now/terms) name the operator; a reply
   from an `@publica.now` address, or a DNS TXT record, also works. Do not use a Gmail
   address: official-looking plugins from free-mail accounts are flagged for trademark
   review.
4. **Whitelist `plugins@wordpress.org`** in that mailbox and check the spam folder
   weekly during the review. The address must be read by a human — no ticketing
   auto-responder.
5. **One plugin in the queue per account.** The upload form enforces a maximum of one
   plugin in `new / pending / approved` state per author (ten only for authors with more
   than one million active installs). Do not submit anything else from this account
   until Publica.now is approved, and never use a second account to get around it (all
   secondary accounts get suspended).
6. **Install Subversion locally** for the post-approval steps — this Mac has none:
   `brew install svn`.

## 1. Before you upload — dependencies and gates

Tick every line. Each one is a known first-pass rejection or an upload-blocking error.

- [ ] **Layer 3 is live:** https://publica.now/wordpress returns 200. It is the
      `Plugin URI:` header, the "Docs" plugin-row link and a link in the readme.
      Reviewers click Plugin URIs; a 404 is an easy "incomplete" verdict. (Today it is a
      404 — Layer 3 ships after the plugin stabilises.)
- [ ] **Screenshots captured** into `.wordpress-org/screenshot-1.png … screenshot-6.png`,
      one per line of `readme.txt` → *Screenshots*, in that order, all the **same aspect
      ratio** (the June 2026 gallery lays uniform sets out as a grid, mixed sets as
      masonry), lowercase filenames, PNG, each under 10 MB:
      1. Settings → Publica.now before connecting (paste the profile URL)
      2. Connected: name, avatar, works count, "verified website" badge
      3. The Catalog block in the editor with its live preview and options
      4. The catalog on the front end: three-column grid with covers, prices, badges, Buy buttons
      5. A single work with a sale price and the Order paperback button
      6. The shortcode cheat sheet on the settings screen
- [ ] **CI is green on `main`** (PHPCS on PHP 7.4 and 8.3, `php -l`, block script syntax,
      Plugin Check `plugin_repo`, zip build). `https://github.com/publicala/publica-now-wordpress/actions`
- [ ] **Plugin Check locally, both modes** — this is exactly what the upload form runs
      (severity ≥ 7 errors block the upload) and what the reviewer runs afterwards:
      ```sh
      npx wp-env start
      npm run check:repo     # the upload gate: --categories=plugin_repo --error-severity=7 --warning-severity=6 --include-low-severity-errors
      npm run check          # every category, for the human review
      ```
      Expected: no `ERROR` and no `WARNING` inside the plugin files (the integration
      pass of 2026-08-28 was clean on the built zip). Dev files in the repository
      root (`.editorconfig`, `bin/`, `phpcs.xml.dist`…) are flagged when Plugin Check
      runs against the working tree; they are excluded by `.distignore` and absent
      from the zip, so always judge the zip, not the checkout.
- [ ] **Readme validator:** paste `readme.txt` into
      https://wordpress.org/plugins/developers/readme-validator/ . Expected: no errors,
      no warnings. (The validator page needs a browser session; it does not accept a raw
      POST.)
- [ ] **`Tested up to: 7.1` is still the current WordPress release.** Check
      https://api.wordpress.org/core/version-check/1.7/ . If 7.2 has shipped, install
      the plugin on 7.2 in wp-env (`"core": "WordPress/WordPress#7.2"` in
      `.wp-env.override.json`), then change `Tested up to` in `readme.txt`. A value below
      the current release is an **upload-blocking error** (`outdated_tested_upto_header`);
      a value above the next release is too.
- [ ] **Versions agree:** `Version: 1.0.0` header, `PUBLICANOW_VERSION`, readme
      `Stable tag: 1.0.0`. `bin/build-zip.sh` refuses to build otherwise. Digits and
      periods only — the upload form rejects `1.0.0-beta`.
- [ ] **No code that gates features on a publica.now plan** (Guideline 5 — trialware).
      Everything the plugin can do works against the production API with a free account.
- [ ] **Fresh install smoke test** in wp-env from the zip (Plugins → Add New → Upload
      Plugin): activate with `WP_DEBUG` on → no notices; connect a real creator; a page
      with the Catalog block renders; deactivate → reactivate; delete → options and
      transients gone (`wp option list --search='publicanow_*'` returns nothing).

## 2. Build the zip

```sh
cd ~/publica-now-wordpress
git switch main && git pull
bash bin/build-zip.sh
# → dist/publica-now/            (what goes to SVN trunk/)
# → dist/publica-now-1.0.0.zip   (what you upload)
unzip -l dist/publica-now-1.0.0.zip | head -60
```

The script applies `.distignore`, runs `php -l` on every file, and **fails** if the
staged plugin contains any dotfile (`.gitignore`, `.distignore`, `.gitkeep`, `.DS_Store`
— all are errors on WordPress.org's production Plugin Check), any archive/`.sh`/`.phar`
(refused by the upload form), or a filename with spaces. The top-level folder inside the
zip is `publica-now/` and the main file is `publica-now/publica-now.php`, as the slug
requires. The zip must stay under 10 MB (it is about 100 KB).

Do **not** hand-edit `dist/`. If something is wrong, fix the source, commit, rebuild.

## 3. The upload form — exact answers

URL: https://wordpress.org/plugins/developers/add/ (logged in as `publicala`, 2FA on).

The page shows the current queue length and, if you already have a plugin in review, its
status instead of the form. Otherwise:

**Step 1: Before you submit** — three required checkboxes:

- [x] "I have read the Frequently Asked Questions."
- [x] "I have read and make sure that this plugin complies with all of the Plugins Directory Guidelines."
- [x] "I confirm that the plugin has been tested with the Plugin Check plugin, and all indicated issues resolved (apart from what I believe to be false-positives)."

**Step 2: Common reasons plugins are rejected** — three required checkboxes:

- [x] "I have chosen a plugin name that is not confusingly similar to existing plugins, projects, organizations, or trademarks. I searched on the internet for similar names and found nothing similar."
  (True: `publica-now`, `publicanow`, `publica`, `publica-la`, `publicala` all return
  "Plugin not found" on `https://api.wordpress.org/plugins/info/1.0/{slug}.json`, and no
  trademark-list entry matches `publica` or `now`.)
- [x] "I have permission to upload this plugin to WordPress.org for others to use and share and I am using a WordPress.org account that accurately represents the plugin owner: publicala."
- [x] "I confirm that my plugin code does not include artificial limitations to the included functionality…"

**Step 3: Submission acknowledgement** — two required checkboxes about guideline
compliance and continued hosting.

**Select categories (up to 3):** pick the three closest to selling digital content and
blocks — *eCommerce*, *Blocks*, and *Publishing* / *Media* (the list is a fixed taxonomy;
choose what is offered, they can be edited later from the plugin's Advanced page).

**Select File:** `dist/publica-now-1.0.0.zip`.

**Additional Information** (free text; it goes into the reviewer's audit log — keep it
factual, in English). Paste:

> Publica.now is the official WordPress plugin for publica.now (https://publica.now), a
> creator storefront service operated by Publica.la; I am submitting it as an employee
> from our company account. The plugin is serviceware (Guideline 6): after the site owner
> connects their publica.now account on Settings → Publica.now, it registers a read-only
> OAuth client with publica.now (RFC 7591 self-registration, scope catalog:read), reads the
> creator's public catalog through https://publica.now/api/v1/public/*, caches it in
> transients, and renders it with three dynamic blocks and three shortcodes. Buy / Read
> free / Order paperback buttons link to checkout and reader pages hosted on publica.now;
> no payment, file, or buyer data ever touches WordPress. Nothing is sent to publica.now
> before the administrator clicks Connect. The readme's "External Services" section
> lists every request, when it happens, what is sent (creator slug, site host name,
> standard HTTP metadata) and links to https://publica.now/terms and
> https://publica.now/privacy. There is no tracking, no "powered by" link (an opt-in
> setting exists, off by default), and no paid tier inside the plugin. Source:
> https://github.com/publicala/publica-now-wordpress . Plugin Check (plugin_repo,
> severity ≥ 7) passes locally; PHPCS WordPress + WordPress-Extra + PHPCompatibilityWP
> 7.4- passes in CI.

Click **Upload**. The upload handler runs, in order: zip hygiene (no VCS dirs, no
archives/`.sh`), `Plugin Name` present and valid, reserved-slug list, **trademark check
on the name and then on the slug**, slug/name not already taken, "known to exist in the
wild" check, `Description:` and numeric `Version:` present, `Plugin URI` ≠ `Author URI`,
readme present with a `License`, then **Plugin Check** with a 45-second budget. Any
failure returns an error and the plugin is not queued; fix and upload again.

### 3.1 Immediately after a successful upload — the slug

The slug is generated from the `Plugin Name:` header by removing every character outside
`a-z 0-9 space _ . -`, turning `_` into `-` and running `sanitize_title_with_dashes()`
(which converts `.` to `-`). With the header
`Publica.now – Sell Ebooks, Audiobooks, Video & Print Books` the **assigned slug will be
`publica-now-sell-ebooks-audiobooks-video-print-books`**, not `publica-now`.

The submission page shows *"Current assigned slug: …"* with a **change** link. You may
change the slug **once**, only before the review starts:

1. Click **change** → the "Request to change your plugin slug" dialog.
2. **Desired Slug:** `publica-now`
3. Tick "I confirm that my slug choice meets the guidelines for plugin slugs." → **Request**.

The page warns that the chosen slug "cannot be guaranteed, and is subject to change based
on the results of your review" — a brand slug from the brand's verified owner is exactly
the case Guideline 17 allows, so expect it to stand. If the change link is not shown, or
if you prefer a deterministic slug, use the alternative **before** uploading: set
`Plugin Name: Publica.now` in `publica-now.php` (one line; the readme's `=== … ===` title
stays long and is what the directory displays), rebuild, upload — the generated slug is
then `publica-now` by construction. Plugin Check will show a `mismatched_plugin_name`
**warning** (not an error) for the header/readme difference; note it in Additional
Information. Once a plugin is approved the slug can never change.

### 3.2 Also on the submission page

- **Check with Plugin Check** — shows the full Plugin Check result list for the uploaded
  zip (the upload only shows blocking errors). Read it; anything you cannot justify as a
  false positive, fix and re-upload.
- **Upload updated "…" plugin for review** — replaces the zip without losing your queue
  position. Use it whenever you fix something before or during the review; add a one-line
  "Additional Information" comment saying what changed.
- Reviews are conducted **in English only**, by e-mail, from `plugins@wordpress.org`.

## 4. What reviewers run, and what we ran first

| Check | Reviewer | Us, beforehand |
| --- | --- | --- |
| Plugin Check, `plugin_repo` category, error severity ≥ 7 | on upload (automated) and by hand (all categories, including `prefixing`) | `npm run check:repo`, `npm run check`, CI job *Plugin Check* on the built zip |
| Internal AI-assisted scan + human read of every PHP file (escaping, sanitising, nonces, capability checks, prefixes, direct file access, remote assets, `wp_remote_*` vs curl, heredoc, short tags) | yes | PHPCS `WordPress` + `WordPress-Extra` + `PHPCompatibilityWP` (`phpcs.xml.dist`) on PHP 7.4 and 8.3 in CI |
| Readme: English, complete, External Services disclosure with ToS/privacy links, `Tested up to` current, `Stable tag` = version, ≤ 5 tags, short description ≤ 150 chars, no template text | yes | readme validator; `bin/build-zip.sh` cross-checks versions |
| Install on the current WordPress with `WP_DEBUG` on | yes | wp-env smoke test (§1) |
| Trademark / name / slug | at upload (automated) and by hand | slug availability + trademark list checked 2026-08-28 |

## 5. The review — timeline and how to answer

- After upload you receive an automated confirmation. The plugin then sits in the queue as
  *Awaiting Review*. Published figures, week of 2026-08-17: ~700 new submissions per week,
  ~270 not yet processed, the team aims for a first look **within 5 business days**
  (handbook: up to 14 business days; "all plugins get an initial review within four
  weeks"). Approvals in 2025: 69.5 % of reviewed plugins; 38.7 % of plugins never
  answered the review e-mail and were closed.
- Issues arrive in **one e-mail thread** with the subject
  `[WordPress Plugin Directory] Review in Progress: Publica.now – Sell Ebooks, Audiobooks, Video & Print Books`
  (the display name at that point). Answer in that thread: **Reply All**, keep the subject
  line, keep the quoted history. Never open a new thread and never re-submit the plugin
  for a review issue — "DO NOT resubmit your plugin if it was rejected for any other
  reason, just reply to the email."
- Reply **within 10 business days** every time; the submission is auto-rejected after
  three months of silence (the thread stays open; you would resubmit and reply).
- For each point the reviewer raises: fix it in git (one commit per point, referenced in
  the reply), rebuild, upload the new zip from the submission page, then reply with what
  changed and where (file + line). If a point is a false positive, say so once, with the
  reason, and offer a change anyway if they insist — arguing costs weeks.
- Do not ask for expedition; there is none except for security or legal reasons.
- Approval e-mail: "Approved — Please check your email for instructions on uploading your
  plugin" plus the SVN URL `https://plugins.svn.wordpress.org/publica-now`. Nothing is live
  until the first commit.

## 6. After approval — SVN

Two ways to make the first commit. **Recommended: the GitHub Action after a dry run**
(§7), because it commits exactly `dist/publica-now/` and `.wordpress-org/` and we never
touch SVN by hand again. The manual commands below are the reference for what the action
does and the fallback when a step needs a human.

```sh
brew install svn                                        # once
cd ~
svn checkout https://plugins.svn.wordpress.org/publica-now publica-now-svn
cd publica-now-svn                                      # contains assets/ branches/ tags/ trunk/

# 1. trunk = the built plugin (main file and readme at trunk root, never trunk/publica-now/)
( cd ~/publica-now-wordpress && bash bin/build-zip.sh )
rsync -a --delete --exclude='.svn' ~/publica-now-wordpress/dist/publica-now/ trunk/

# 2. assets = banners, icons, screenshots (NOT inside the plugin, NOT in trunk/assets)
rsync -a --delete --exclude='.svn' ~/publica-now-wordpress/.wordpress-org/ assets/

# 3. tell SVN about new/removed files
svn add --force trunk assets
svn status | grep '^!' | awk '{print $2}' | xargs -r svn delete

# 4. image MIME types, or the directory serves them as downloads
svn propset svn:mime-type image/png  assets/*.png
svn propset svn:mime-type image/svg+xml assets/*.svg

# 5. commit trunk + assets (SVN username is case-sensitive; the password is the SVN
#    password from profiles.wordpress.org → Edit Profile → "Account & Security", not the login password)
svn commit -m "Publica.now 1.0.0: initial release" --username publicala

# 6. tag the release; the Stable tag in trunk/readme.txt (1.0.0) tells the directory which tag to serve
svn copy trunk tags/1.0.0
svn commit -m "Tag 1.0.0" --username publicala
```

Facts about the SVN repository that differ from git: it is a **release** repository, not a
work log — every commit rebuilds every zip, so commit finished versions only (a
readme-only commit to bump `Tested up to` is the accepted exception); never commit zips,
`node_modules`, `vendor` or `.git`; keep a handful of tags at most; the `assets/`
directory sits beside `trunk/` and `tags/`; the CDN caches assets for minutes to hours.
The plugin appears at `https://wordpress.org/plugins/publica-now/` within a few minutes of
the first commit and in directory search **6–14 days** later.

## 7. GitHub Actions secrets for `deploy.yml`

Add **only after approval** (before it, both workflow jobs stop at their first step with
an explicit message):

`https://github.com/publicala/publica-now-wordpress/settings/secrets/actions` → *New
repository secret*:

| Secret | Value |
| --- | --- |
| `SVN_USERNAME` | `publicala` — the WordPress.org username, case-sensitive |
| `SVN_PASSWORD` | the **SVN password** generated at https://profiles.wordpress.org/me/profile/edit/group/3/ (section "SVN password"; it is separate from the login password) |

Then:

1. **Dry run:** Actions → *Deploy to WordPress.org* → *Run workflow* → `dry-run: true`.
   It builds `dist/publica-now`, checks out SVN with the secrets, stages trunk/tag/assets
   and stops before committing. Read the log.
2. **Real release:** create a GitHub release with tag `1.0.0` (or `v1.0.0`; a leading `v`
   is stripped) on `main`. The `release` job verifies the tag equals the `Version:` header,
   refuses pre-releases, and commits `dist/publica-now/` to `trunk/` and `tags/1.0.0/` and
   `.wordpress-org/` to `assets/` with `10up/action-wordpress-plugin-deploy`.
3. Later readme-only or asset-only changes pushed to `main` run the `assets` job
   (`10up/action-wordpress-plugin-asset-update`), which updates `trunk/readme.txt` and
   `assets/` without cutting a release — this is how `Tested up to` bumps and new
   screenshots go out.

Since 2025-10-27 WordPress.org runs Plugin Check on **every** version committed and
e-mails reports; a regression after approval can close the plugin, so the CI Plugin Check
job stays mandatory on every pull request.

## 8. Listing polish (first week after the first commit)

- [ ] Banner (`banner-772x250.png`, `banner-1544x500.png`) and icon (`icon.svg` +
      `icon-128x128.png` / `icon-256x256.png`) show on the listing — regenerate with
      `npm run assets` if the design changes; sizes must match the filenames exactly.
- [ ] Six screenshots show with their readme captions; check the gallery grid.
- [ ] FAQ renders as questions; External Services appears at the end of the Description.
- [ ] **Support forum:** open https://wordpress.org/support/plugin/publica-now/ , click
      *Subscribe* (top right) as `publicala`, and decide who answers; reviewers and users
      judge a plugin by unanswered threads. On the plugin's *Advanced* page set the
      support e-mail if offered.
- [ ] **Translations:** https://translate.wordpress.org/projects/wp-plugins/publica-now/
      fills with strings after the first commit (the shipped `languages/publica-now.pot`
      is only a template; WordPress.org generates its own). Translate Spanish and
      Portuguese in-house (publica.la team) and request PTE status for `publicala` so
      approvals are not blocked on volunteer editors. Do not ship `.mo` files.
- [ ] **Live Preview (optional):** commit `assets/blueprints/blueprint.json` (WordPress
      Playground blueprint that installs and activates the plugin and lands on
      Settings → Publica.now), then a committer enables *Preview* on the plugin's
      Advanced settings. The settings screen previews fine without a publica.now account.
- [ ] Review the listing text once on a phone; the short description is what shows in
      search results.

## 9. First-pass rejection risks specific to this plugin — and the mitigation in place

| # | Risk (guideline / check) | Why it applies to us | Already mitigated by |
| --- | --- | --- | --- |
| 1 | **"Storefronts that are not services"** (Guideline 6; reviewer checklist "marketplace or storefront only plugins") | The plugin renders products and links to external checkout. | The service relationship is real and evident: OAuth client registration, authenticated API reads, catalog/price/availability sync, attribution, revoke on disconnect. The readme's first two paragraphs say so; the Additional Information text says so; the settings screen requires an account. |
| 2 | **Undocumented external service** (Common issues; checklist "Serviceware requirements") | Every render may call publica.now; visitors load covers from it. | `readme.txt` → *External Services*: what is sent, when, what is received, ToS + privacy links (both verified 200 on 2026-08-28). The account requirement is stated in Installation, FAQ and on the settings page. |
| 3 | **Phone-home without consent** (Guideline 7) | A plugin that called the API on activation or in Site Health before connecting would fail. | No request is made until Connect is clicked; no version pings, no telemetry. Verified in code: `Site_Health::test_connection()` returns "not connected" before any request, and the REST `works`/`status` routes answer `publicanow_not_connected` without calling the API. Re-check after any change to those two files. |
| 4 | **Trademark / ownership of a brand-first slug** (Guideline 17) | Name and slug begin with "Publica.now". | Submitted from the company account with a company e-mail; proof of publica.now control ready (§0.3). |
| 5 | **Generated slug is not `publica-now`** | Slug derives from the long `Plugin Name`. | §3.1: one-time slug change request, or the short-header alternative. |
| 6 | **Remote assets** (Guideline 8; Plugin Check `offloading_files`) | Covers and avatars are served by publica.now. | They are service content returned by the API (allowed, disclosed). All CSS/JS/icons ship in the zip; no hardcoded `<img src="https://…">` strings in PHP; no CDN hosts anywhere. |
| 7 | **Unescaped output / unsanitised input / missing nonces** (the top three rejection reasons) | Any plugin. | PHPCS `WordPress-Extra` (EscapeOutput, ValidatedSanitizedInput, NonceVerification) blocks CI; all admin POSTs use `check_admin_referer` + `manage_options`; REST routes have permission callbacks; templates escape at output. |
| 8 | **Prefixing** (Plugin Check `prefixing`, excluded from the upload gate but read by reviewers) | Any plugin. | Everything is `publicanow_` / `PublicaNow` / `PUBLICANOW_`; PHPCS `PrefixAllGlobals` enforces it. |
| 9 | **Dev files or dotfiles in the zip** (Plugin Check `file_type`, error on production) | The repo carries `.github`, `.wp-env.json`, `.distignore`, `.gitkeep`, docs, bin. | `.distignore` + `bin/build-zip.sh`, which fails the build if any dotfile or archive survives. |
| 10 | **`Tested up to` below the current release** (`outdated_tested_upto_header`, blocks upload) | WordPress ships roughly three majors a year. | §1 check on the day of upload; readme-only bump via the `assets` job later. |
| 11 | **`Stable tag` / `Version` / license mismatch** (severity 9) | Three places to keep in sync. | `bin/build-zip.sh` refuses to build on mismatch; header and readme both say `GPLv2 or later` + the same URI. |
| 12 | **Readme not in English / > 5 tags / short description > 150 chars / template text left** | Marketing is trilingual. | readme is English only; 5 tags; 147-char short description; no template text. Validator run in §1. |
| 13 | **Plugin URI 404** | Layer 3 page not live yet. | §1 first checkbox. Fallback: point `Plugin URI:` at the GitHub repository (it must still differ from `Author URI`). |
| 14 | **WordPress functions newer than `Requires at least: 6.2`** (Plugin Check `wp_functions_compatibility`) | Easy to slip in (`wp_get_wp_version()` is 6.7, `wp_admin_notice()` is 6.4, `wp_trigger_error()` 6.4). | PHPCS `minimum_supported_wp_version 6.2` warns; Plugin Check job in CI errors. |
| 15 | **`load_plugin_textdomain()` discouraged** (Plugin Check warning) | Tempting to add "for sites that install from GitHub". | Not called: WordPress ≥ 4.6 loads WordPress.org language packs just in time, and the plugin ships no `.mo` files. Keep it out. |
| 16 | **Trialware / paid tier in code** (Guideline 5) | The service has paid features. | No feature in the plugin is gated on a plan; everything gates server-side on publica.now. |
| 17 | **Compliance claims** (Guideline 9) | Tempting to say "handles VAT". | The readme says publica.now handles checkout, payment processing, delivery, refunds and payouts; it makes no tax/VAT/GDPR compliance claim. Keep it that way in future copy. |
| 18 | **"Powered by" credit** (Guideline 10) | `show_powered_by` exists. | Default **false**; opt-in only. |
| 19 | **External URL as `add_menu_page()` target** (Plugin Check error) | Links to the publica.now dashboard. | Only a `Settings → Publica.now` options page; external links are ordinary anchors on that page and in the plugin-row meta. |
| 20 | **Block API version** (`block.json` `apiVersion` < 3 is an error for the WordPress 7 iframed editor) | Three blocks. | All three declare `apiVersion: 3`; CI's block.json step fails otherwise. |

## 10. After approval — close the loop

1. **publica.now Layer 3:** update https://publica.now/wordpress so its primary CTA points at
   https://wordpress.org/plugins/publica-now/ (keep the GitHub release zip as the secondary
   link); add the listing to `/devs#embeds`, `llms.txt`, `AGENTS.md` and
   `skills/publica-now/SKILL.md`; ship the dashboard share card
   (`/dashboard/works/{id}/share` → "Sell it on your WordPress site" with
   `[publicanow_work id="…"]` prefilled).
2. **Announce:** blog post / newsletter to creators (publica.now's own e-mail stack, never
   rondine), social posts, and a note to the publica.la publisher list — the plugin is
   also an argument for publishers with WordPress sites.
3. **README.md:** add the listing URL and the "Requires WordPress 6.2+" badge-free line
   pointing at the directory; keep `docs/PLAN.md` as the contract (change the document
   first from now on).
4. **Watch:** the support forum subscription, the Plugin Check e-mails on every release,
   active-install and download stats on the listing's *Advanced* page, and the
   `utm_campaign=publica-now-plugin` attribution in the publica.now revenue ledger — that
   number is the plugin's success metric.
5. **Keep it current:** bump `Tested up to` within a week of every WordPress major
   (readme-only commit through the `assets` job); rebuild banners/icons with
   `npm run assets` if the brand changes; never let a release out without the CI Plugin
   Check job passing.

## Appendix — useful URLs

- Add your plugin: https://wordpress.org/plugins/developers/add/
- Detailed guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Common issues: https://developer.wordpress.org/plugins/wordpress-org/common-issues/
- Readme spec: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- Readme validator: https://wordpress.org/plugins/developers/readme-validator/
- Assets (banners, icons, screenshots): https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- SVN how-to: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
- Plugin Check: https://wordpress.org/plugins/plugin-check/ — CLI docs https://github.com/WordPress/plugin-check/blob/trunk/docs/CLI.md
- Developer FAQ: https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- Plugins team updates (queue length): https://make.wordpress.org/plugins/
- WordPress.org Plugin Directory MCP server (readme validation, submission status from an agent): `npx -y @wporg/mcp`
