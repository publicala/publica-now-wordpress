#!/usr/bin/env bash
#
# Regenerate the WordPress.org listing assets in .wordpress-org/ from the
# HTML/SVG sources in bin/assets-src/ and assets/images/icon.svg.
#
# Outputs (exact sizes are mandatory: WordPress.org matches on the filename):
#   .wordpress-org/icon.svg            copy of assets/images/icon.svg
#   .wordpress-org/icon-128x128.png    transparent PNG fallback for the SVG
#   .wordpress-org/icon-256x256.png    transparent PNG fallback for the SVG
#   .wordpress-org/banner-772x250.png  the base banner
#   .wordpress-org/banner-1544x500.png the same page at device scale factor 2
#
# Rasteriser: any Chrome-family binary in headless mode. Override with
#   CHROME_BIN=/path/to/chrome bin/make-assets.sh
#
# Screenshots are taken from file:// URLs, so the pages must not depend on
# the network. Fonts fall back to Georgia / New York / system sans.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/bin/assets-src"
OUT="$ROOT/.wordpress-org"

log() { printf '\033[1;34m[make-assets]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[make-assets] error:\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 1. Find a Chrome-family binary.
#    Order: explicit override, then macOS app bundles, then Linux binaries,
#    then the Chromium that Playwright installs in its cache.
# ---------------------------------------------------------------------------
find_chrome() {
	if [ -n "${CHROME_BIN:-}" ]; then
		[ -x "$CHROME_BIN" ] || die "CHROME_BIN is set but not executable: $CHROME_BIN"
		printf '%s' "$CHROME_BIN"
		return
	fi

	local candidates=(
		"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
		"/Applications/Chromium.app/Contents/MacOS/Chromium"
		"/Applications/Brave Browser.app/Contents/MacOS/Brave Browser"
		"/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge"
		"$HOME/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
	)
	local c
	for c in "${candidates[@]}"; do
		if [ -x "$c" ]; then
			printf '%s' "$c"
			return
		fi
	done

	local name
	for name in google-chrome google-chrome-stable chromium chromium-browser brave-browser microsoft-edge; do
		if command -v "$name" >/dev/null 2>&1; then
			command -v "$name"
			return
		fi
	done

	# Playwright's cached Chromium (macOS layout, newest build first).
	local pw
	for pw in "$HOME/Library/Caches/ms-playwright" "$HOME/.cache/ms-playwright"; do
		if [ -d "$pw" ]; then
			c="$(find "$pw" -maxdepth 4 -type f \( -name 'Chromium' -o -name 'chrome' \) -path '*chromium*' 2>/dev/null | sort -r | head -n1 || true)"
			if [ -n "$c" ] && [ -x "$c" ]; then
				printf '%s' "$c"
				return
			fi
		fi
	done

	die "No Chrome-family browser found. Install Google Chrome or run: npx playwright install chromium (then re-run)."
}

CHROME="$(find_chrome)"
log "rasteriser: $CHROME"

# ---------------------------------------------------------------------------
# 2. Render one page to one PNG.
#    A throw-away profile directory keeps headless Chrome independent from any
#    running browser session (a shared profile makes --headless hang).
#    Chrome is started in the background and killed once the PNG is on disk:
#    recent "--headless=new" builds sometimes never exit after --screenshot.
#    Small --window-size values are clamped to Chrome's minimum window but the
#    screenshot is still cropped to the requested size from the top-left, so
#    every source page anchors its artwork at (0,0) with fixed dimensions.
# ---------------------------------------------------------------------------
render() {
	local url="$1" width="$2" height="$3" scale="$4" out="$5"
	local profile pid waited=0 size_a size_b
	profile="$(mktemp -d "${TMPDIR:-/tmp}/publicanow-chrome.XXXXXX")"

	rm -f "$out"
	"$CHROME" \
		--headless=new \
		--disable-gpu \
		--hide-scrollbars \
		--no-first-run \
		--no-default-browser-check \
		--disable-extensions \
		--user-data-dir="$profile" \
		--default-background-color=00000000 \
		--force-device-scale-factor="$scale" \
		--window-size="${width},${height}" \
		--virtual-time-budget=3000 \
		--screenshot="$out" \
		"$url" >/dev/null 2>&1 &
	pid=$!

	# Wait up to 60 s for the file to appear, then for its size to settle.
	while [ ! -s "$out" ] && [ "$waited" -lt 120 ]; do
		sleep 0.5
		waited=$(( waited + 1 ))
	done
	if [ -s "$out" ]; then
		size_a="$(wc -c < "$out")"
		sleep 0.5
		size_b="$(wc -c < "$out")"
		while [ "$size_a" != "$size_b" ]; do
			size_a="$size_b"
			sleep 0.5
			size_b="$(wc -c < "$out")"
		done
	fi

	# Polite stop first; Chrome's helper processes only die reliably with SIGKILL.
	kill "$pid" >/dev/null 2>&1 || true
	sleep 1
	kill -9 "$pid" >/dev/null 2>&1 || true
	wait "$pid" 2>/dev/null || true
	pkill -9 -f "user-data-dir=$profile" >/dev/null 2>&1 || true
	rm -rf "$profile"

	[ -s "$out" ] || die "Chrome produced no output for $url -> $(basename "$out")"
}

# ---------------------------------------------------------------------------
# 3. Verify a PNG's pixel dimensions (and alpha for icons) from its IHDR
#    chunk, without ImageMagick or Pillow.
# ---------------------------------------------------------------------------
verify_png() {
	local file="$1" want_w="$2" want_h="$3" want_alpha="$4"
	python3 - "$file" "$want_w" "$want_h" "$want_alpha" <<'PY'
import struct, sys
path, want_w, want_h, want_alpha = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4] == "1"
with open(path, "rb") as fh:
    head = fh.read(33)
if head[:8] != b"\x89PNG\r\n\x1a\n" or head[12:16] != b"IHDR":
    sys.exit(f"{path}: not a PNG")
w, h = struct.unpack(">II", head[16:24])
color_type = head[25]
has_alpha = color_type in (4, 6)
problems = []
if (w, h) != (want_w, want_h):
    problems.append(f"size {w}x{h}, expected {want_w}x{want_h}")
if want_alpha and not has_alpha:
    problems.append(f"color type {color_type} has no alpha channel")
if problems:
    sys.exit(f"{path}: " + "; ".join(problems))
print(f"  ok  {path.rsplit('/', 1)[-1]}  {w}x{h}  alpha={'yes' if has_alpha else 'no'}")
PY
}

mkdir -p "$OUT"

# The SVG icon is the source of truth; WordPress.org requires a PNG fallback beside it.
cp "$ROOT/assets/images/icon.svg" "$OUT/icon.svg"
log "copied icon.svg"

log "rendering icons"
render "file://$SRC/icon.html?size=128" 128 128 1 "$OUT/icon-128x128.png"
render "file://$SRC/icon.html?size=256" 256 256 1 "$OUT/icon-256x256.png"

log "rendering banners"
render "file://$SRC/banner.html" 772 250 1 "$OUT/banner-772x250.png"
render "file://$SRC/banner.html" 772 250 2 "$OUT/banner-1544x500.png"

log "verifying"
verify_png "$OUT/icon-128x128.png"    128  128 1
verify_png "$OUT/icon-256x256.png"    256  256 1
verify_png "$OUT/banner-772x250.png"  772  250 0
verify_png "$OUT/banner-1544x500.png" 1544 500 0

# WordPress.org limits: banners 4 MB, icons 1 MB. Fail loudly instead of at upload.
check_size() {
	local file="$1" max_kb="$2" kb
	kb=$(( $(wc -c < "$file") / 1024 ))
	[ "$kb" -le "$max_kb" ] || die "$(basename "$file") is ${kb} KB; WordPress.org allows at most ${max_kb} KB"
}
check_size "$OUT/banner-772x250.png"  4096
check_size "$OUT/banner-1544x500.png" 4096
check_size "$OUT/icon-128x128.png"    1024
check_size "$OUT/icon-256x256.png"    1024

log "done: $(ls "$OUT" | tr '\n' ' ')"
