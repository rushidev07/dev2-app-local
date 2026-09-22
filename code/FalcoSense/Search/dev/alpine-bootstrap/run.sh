#!/usr/bin/env bash
#
# Verifies the Alpine loader in view/frontend/templates/alpine-bootstrap.phtml.
#
#   none : host has no Alpine        -> we must inject exactly one copy
#   v3   : host already has Alpine 3 -> we must inject nothing (Hyvä/Everest)
#   v2   : host has Alpine 2         -> we must inject nothing, and warn
#   e2e  : no Alpine, real directives-> x-text / x-for / x-show must all work
#
# The scenario pages embed the bootstrap logic extracted from the .phtml. If you
# change that template, re-extract (see README) before trusting these results.
set -uo pipefail
cd "$(dirname "$0")"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
if [ ! -x "$CHROME" ]; then CHROME="$(command -v google-chrome || command -v chromium || true)"; fi
if [ -z "$CHROME" ] || [ ! -x "$CHROME" ]; then echo "Chrome not found; set CHROME=" >&2; exit 2; fi
for s in none v3 v2 e2e; do
  "$CHROME" --headless --disable-gpu --no-sandbox --allow-file-access-from-files \
            --virtual-time-budget=4000 --dump-dom "file://$PWD/boot-$s.html" 2>/dev/null \
  | python3 -c "
import sys,re,html
d=sys.stdin.read(); m=re.search(r'<pre id=\"out\">(.*?)</pre>', d, re.S)
print((html.unescape(m.group(1)) if m else 'NO OUTPUT for $s')+'\n')"
done
