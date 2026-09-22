#!/usr/bin/env bash
#
# CSS parity + isolation checks for FalcoSense_Search.
#
#   ./run.sh              run both checks
#   ./run.sh parity       old Tailwind markup vs new semantic CSS, computed-style diff
#   ./run.sh base         isolation layer vs a deliberately hostile host theme
#
# Exits non-zero if the parity check reports a difference, so it can gate a commit.
#
# Uses Chrome headless directly rather than Playwright: this repo's Node is
# v14 and Playwright needs 16+. Avoids bash-4 syntax; macOS ships bash 3.2.
set -uo pipefail
cd "$(dirname "$0")"

CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
if [ ! -x "$CHROME" ]; then
    CHROME="$(command -v google-chrome || command -v chromium || true)"
fi
if [ -z "$CHROME" ] || [ ! -x "$CHROME" ]; then
    echo "Chrome not found. Set CHROME=/path/to/chrome" >&2
    exit 2
fi

run() {
    "$CHROME" --headless --disable-gpu --no-sandbox --allow-file-access-from-files \
              --virtual-time-budget=3000 --dump-dom "file://$PWD/$1" 2>/dev/null \
    | python3 -c "
import sys, re, html
d = sys.stdin.read()
m = re.search(r'<pre id=\"out\">(.*?)</pre>', d, re.S)
print(html.unescape(m.group(1)) if m else 'NO OUTPUT CAPTURED')
"
}

WHAT="${1:-all}"
status=0

if [ "$WHAT" = "parity" ] || [ "$WHAT" = "all" ]; then
    echo "=== parity: original Tailwind vs semantic CSS ==="
    out=$(run parity.html)
    echo "$out"
    echo "$out" | grep -q "differing: 0" || status=1
    echo
fi

if [ "$WHAT" = "admin" ] || [ "$WHAT" = "all" ]; then
    echo "=== admin style config still drives the new CSS ==="
    # global-style-vars.phtml emits --ahy-* on :root from Stores > Configuration.
    # fs-tokens.css defers to those via var(--ahy-..., default). If someone ever
    # hardcodes a value there instead, "configured" below stops differing from
    # "unset" and every merchant's card styling silently reverts to defaults.
    run admin-unset.html
    run admin-configured.html
    echo
fi

if [ "$WHAT" = "base" ] || [ "$WHAT" = "all" ]; then
    echo "=== isolation: fs-base.css vs hostile host theme ==="
    run base-test.html
    echo
fi

if [ $status -eq 0 ]; then
    echo "PASS"
else
    echo "FAIL - computed styles diverged from the Tailwind original"
fi
exit $status
