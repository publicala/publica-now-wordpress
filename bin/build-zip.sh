#!/usr/bin/env bash
#
# Build the distributable plugin exactly as it is uploaded to WordPress.org
# and committed to SVN trunk/:
#
#   dist/publica-now/                  the plugin folder, filtered by .distignore
#   dist/publica-now-{version}.zip     the zip, with publica-now/ as top-level folder
#
# The version is read from the "Version:" header of publica-now.php and must
# match PUBLICANOW_VERSION and the readme "Stable tag"; a mismatch is a
# WordPress.org upload error (Plugin Check: stable_tag_mismatch, severity 9),
# so the build refuses to continue.
#
# Usage: bin/build-zip.sh            (from anywhere; paths are resolved)
#        VERSION_OVERRIDE=1.0.1 bin/build-zip.sh   only for local experiments

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="publica-now"
MAIN="$ROOT/$SLUG.php"
README="$ROOT/readme.txt"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"

log() { printf '\033[1;34m[build-zip]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[build-zip] error:\033[0m %s\n' "$*" >&2; exit 1; }

command -v rsync >/dev/null 2>&1 || die "rsync is required"
command -v zip   >/dev/null 2>&1 || die "zip is required"
[ -f "$MAIN" ]   || die "main plugin file not found: $MAIN"
[ -f "$README" ] || die "readme.txt not found: $README"
[ -f "$ROOT/.distignore" ] || die ".distignore not found"

# ---------------------------------------------------------------------------
# 1. Version: header, constant and readme must agree.
# ---------------------------------------------------------------------------
HEADER_VERSION="$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*([0-9][0-9.]*)[[:space:]]*$/\1/p' "$MAIN" | head -n1)"
CONST_VERSION="$(sed -nE "s/^define\( 'PUBLICANOW_VERSION', '([^']+)' \);.*$/\1/p" "$MAIN" | head -n1)"
STABLE_TAG="$(sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p' "$README" | head -n1)"

VERSION="${VERSION_OVERRIDE:-$HEADER_VERSION}"
[ -n "$VERSION" ] || die "could not read the Version: header from $MAIN"

# WordPress.org's upload form accepts digits and periods only (no 1.0.0-beta).
printf '%s' "$VERSION" | grep -Eq '^[0-9]+(\.[0-9]+)*$' || die "version '$VERSION' must contain digits and periods only"

if [ -z "${VERSION_OVERRIDE:-}" ]; then
	[ "$CONST_VERSION" = "$VERSION" ] || die "PUBLICANOW_VERSION ($CONST_VERSION) differs from the Version: header ($VERSION)"
	[ "$STABLE_TAG" = "$VERSION" ]    || die "readme.txt Stable tag ($STABLE_TAG) differs from the Version: header ($VERSION)"
fi
log "version $VERSION"

# ---------------------------------------------------------------------------
# 2. Stage: copy everything not excluded by .distignore.
#    /dist is excluded explicitly too, so re-running never nests a build.
# ---------------------------------------------------------------------------
rm -rf "$STAGE" "$DIST/$SLUG-$VERSION.zip"
mkdir -p "$STAGE"
rsync -a --delete \
	--exclude-from="$ROOT/.distignore" \
	--exclude='/dist' \
	"$ROOT/" "$STAGE/"

# ---------------------------------------------------------------------------
# 3. Hygiene gates that mirror WordPress.org's automated checks.
# ---------------------------------------------------------------------------
# a) Dotfiles anywhere in the zip are an error on the WordPress.org gate.
if find "$STAGE" -name '.*' -print -quit | grep -q .; then
	find "$STAGE" -name '.*' -print >&2
	die "dotfiles found in the staged plugin; add them to .distignore"
fi

# b) Archives, phar and shell scripts are refused by the upload form.
if find "$STAGE" -type f \( -name '*.zip' -o -name '*.gz' -o -name '*.tgz' -o -name '*.tar' -o -name '*.rar' -o -name '*.7z' -o -name '*.phar' -o -name '*.sh' \) -print -quit | grep -q .; then
	die "forbidden file types found in the staged plugin"
fi

# c) Names with spaces or shell metacharacters fail Plugin Check's file_type check.
if find "$STAGE" -name '* *' -print -quit | grep -q .; then
	die "file or folder names with spaces found in the staged plugin"
fi

# d) Every PHP file must parse (the reviewer runs PHP with WP_DEBUG on).
if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' file; do
		php -l "$file" >/dev/null 2>&1 || die "PHP syntax error in ${file#"$STAGE"/}"
	done < <(find "$STAGE" -type f -name '*.php' -print0)
	log "php -l passed"
else
	log "php not on PATH; skipping syntax check"
fi

# e) The essentials must be present.
for required in "$SLUG.php" readme.txt uninstall.php; do
	[ -f "$STAGE/$required" ] || die "required file missing from the build: $required"
done

# ---------------------------------------------------------------------------
# 4. Zip with the top-level folder, no extended attributes (-X), no resource
#    forks (the zip CLI never adds __MACOSX; Finder does).
# ---------------------------------------------------------------------------
( cd "$DIST" && zip -rq -X "$SLUG-$VERSION.zip" "$SLUG" )

ZIP="$DIST/$SLUG-$VERSION.zip"
SIZE_KB=$(( $(wc -c < "$ZIP") / 1024 ))
[ "$SIZE_KB" -le 10240 ] || die "zip is ${SIZE_KB} KB; WordPress.org accepts at most 10 MB"

log "built $ZIP (${SIZE_KB} KB)"
log "contents:"
( cd "$STAGE" && find . -type f | sed 's|^\./|  |' | sort )
