# FalcoSense_Search — Real SSR + Shadow DOM Experiment — Handoff

*Written 2026-09-02, at the end of the session that did this work. Read this
whole file before touching anything — it's the complete record of what was
built, why, what was measured, and what's still open. This module
(`FalcoSense_Search`) is the one actually running on production
`everest.com`; a sibling module `Ahy_SmartSearchLuma` (separate repo,
`/Users/11ahyconsulting/Documents/falcosense-shadowdom-module`) also exists
but is NOT what this doc is about — don't confuse the two.*

---

## 0. Read this first — current repo state

Checked immediately before writing this doc (`git log`, `git status`):

- **Working tree is clean** except one pre-existing untracked backup file
  (`code/FalcoSense/Search/ProductImageCompressionService.php.bak_20260803_235439`)
  that is unrelated to this work and was never staged/touched.
- **All commits below are local only — nothing has been pushed to GitHub.**
  The user explicitly said "moving forward dont push in github i will do
  it" and separately "after changes are made dont push it to github i need
  to review it." Do not run `git push` under any circumstance unless
  explicitly asked again in a future session.
- **Deployment to the real dev2 server is manual** — the user copies files
  via FileZilla themselves after being told which files changed, then runs
  whatever CLI commands are given (they have SSH/terminal access to dev2,
  confirmed working in this session — earlier assumptions that they didn't
  were wrong).
- **Full commit history for this work, oldest to newest:**
  ```
  cf2341c  initial commit
  93849e0  Baseline snapshot of dev2-app app/ directory before FalcoSense_Search SSR work
  14f3d89  chore: ignore .env files to prevent committing secrets
  1c8aec1  chore: gitignore Magento env.php files to prevent committing secrets
  d114b8e  Phase 0: flip module flags to benchmark FalcoSense_Search CSR baseline
  3252f7d  Add real SSR to FalcoSense_Search's search results page
  b85892d  Fix stale FPC cache on the native search route + remove debug instrumentation
  49b14a9  Align PHP canonical-view check with JS: exclude bypass_spell requests from SSR
  6a5a68f  Add temporary benchmark logging to isolate module's own timing cost
  6963826  A/B step 1: temporarily disable SSR via kill switch (AB_DISABLE_SSR=true)
  ce3c5d7  A/B step 2: re-enable SSR (AB_DISABLE_SSR=false)
  ffa89eb  Experiment: wrap FalcoSense_Search results grid in Declarative Shadow DOM
  01bf00c  Add real SSR to FalcoSense_Search's category page, for every page/sort
  7b040a9  Fix SSR grid layout glitch: wrap in the same sidebar flex layout
  ```
- **Current live state of key flags** (verified by reading the files, not
  assumed):
  - `Block/Search.php`: `private const AB_DISABLE_SSR = false;` — SSR is
    **ON** for search. This is a **temporary A/B kill switch** left in the
    code, not a permanent feature — see §8.
  - `Service/Plp/FalcoSensePlpProvider.php`: `[SmartSearch][BENCH]` logging
    is **still live** in both `fetchSearch()` and `fetchCategory()` — see
    §8.
  - No shadow DOM kill switch exists — the shadow DOM wrap on
    `search/results.phtml` is unconditional (always active), not A/B-gated.
- **Known discrepancy that was caught and fixed mid-session**: at one point
  the *actual live file on the dev2 server* had `AB_DISABLE_SSR = true`
  (SSR OFF) while this local mirror had `false` (SSR ON) — i.e. what was
  being tested in the browser did NOT match what this repo said. The user
  caught this themselves by opening the real server file via FileZilla's
  edit feature, and fixed it by hand-editing and re-saving that file (which
  re-uploads on save). **Lesson: never trust "the code says X" as proof of
  "the server is running X" — always verify against the actual deployed
  file or the actual HTTP response when it matters.**

---

## 1. Background — why this module, not the other one

Two separate FalcoSense-integration modules exist in this Magento/Hyvä
codebase:

- **`Ahy_SmartSearchLuma`** (repo: `falcosense-shadowdom-module`) — built
  earlier, has its own SSR + CSS isolation work (see that repo's
  `PLP-CSS-ISOLATION-HANDOFF.md` for full details). Uses a hand-rolled
  `all: revert` + `!important` CSS isolation strategy since it deliberately
  does NOT share the host theme's Tailwind classes.
- **`FalcoSense_Search`** (this repo, `dev2-app`) — **the module actually
  running on production `everest.com`**, per the user directly. Its
  backend (sync pipeline, admin theming config, per-subsystem logging) is
  more mature, and its frontend is well-integrated with the Everest2
  Hyvä+Tailwind theme's own design system (shares the theme's compiled
  Tailwind classes and custom color tokens like `ahy-blue`/`ahy-red`/
  `ahy-bg`, rather than isolating from them).

Investigation early in this session found `FalcoSense_Search`'s search and
category grids were **pure client-side rendering (CSR)**: the controllers
returned an empty page shell, and the actual product grid was built
entirely by an Alpine.js component doing `fetch()` **in the browser** after
page load — meaning zero product content existed in the initial HTML
response (bad for SEO/AEO crawlability, and adds a client-side network
round trip before shoppers see anything). This is the exact pattern
`Ahy_SmartSearchLuma` was already rebuilt to avoid. The user asked to bring
the same SSR benefit to `FalcoSense_Search`, explicitly **without**
discarding its existing Tailwind-based styling or its mature backend.

---

## 2. Decisions made, and why (read before changing any of these)

### 2a. Light DOM + SSR, not Shadow DOM, for the *primary* rebuild (category + base search work)

Extensively discussed before any code was written. Three options were
compared explicitly (Light-DOM SSR / Shadow-DOM CSR / Declarative Shadow
DOM shell):

- **Shadow DOM (imperative, `attachShadow()` in JS)** — real style
  isolation, but content only exists after JS runs, which defeats SSR
  entirely (a non-JS-executing crawler sees nothing). Rejected for the
  primary path.
- **Declarative Shadow DOM (`<template shadowrootmode="open">`)** — can
  hold real SSR'd HTML *and* get shadow isolation, since browsers promote
  it during HTML parsing before any JS runs. BUT: non-rendering crawlers
  (GPTBot, ClaudeBot, and similar) are not guaranteed to treat that
  `<template>` content as page content — `<template>` exists specifically
  so its content is *not* part of the normal document by default; only a
  browser that specifically implements the `shadowrootmode` promotion
  step treats it otherwise. A spec-compliant-but-DSD-unaware HTML parser
  (which describes most non-rendering crawlers) would, by default, leave
  that content inert and excluded — the same crawler-invisibility failure
  mode as CSR, just relocated. This is why DSD was **rejected for
  category** (the "main," most SEO/AEO-sensitive pages) but **accepted as
  an explicit, acknowledged-risk experiment on search only** — see §2b.
- **Light DOM + SSR** — the actual product HTML is real, plain HTML in the
  initial response. No crawler-visibility risk of any kind. Chosen as the
  default/primary approach for both search and category.

**Tailwind CSS itself was not re-litigated this session** (that discussion
happened earlier, in the `Ahy_SmartSearchLuma` work, and concluded
Tailwind wasn't worth adding as a new isolation mechanism there). For
`FalcoSense_Search` specifically, the opposite choice was made deliberately
— **lean into** sharing the theme's Tailwind rather than isolating from
it, since that's what makes this module's styling "solid and proper" in
the user's words, and isolating it would mean rebuilding all of that
styling from scratch for no clear benefit (see §2c).

### 2b. Shadow DOM WAS added, but only as an explicit, scoped experiment on search's results grid

After the base SSR work was done and proven for search, the user
specifically asked: *"can we add shadow dom in this? will it make the
module theme compatible and work with SSR at the same time?"* This was
answered honestly with the tradeoffs above, and the user chose to proceed
anyway ("okay so build it lets see what happens") **as an experiment**,
explicitly scoped to search's results page, explicitly NOT extended to
category. The AEO/crawler-visibility risk described in §2a fully applies
to this shadow DOM code — it has NOT been resolved, only accepted as a
known tradeoff for this one page, by the user's explicit choice. See §4b
for what was actually built, and §7 for what was verified working.

**Category deliberately has no shadow DOM** — plain SSR only, same
narrow `!important`-only CSS hardening technique as search's non-shadow
version (see §2c). This was a deliberate choice made when building
category's SSR (not asked about again, since the user's own framing —
"category is main," "every fucking time" — argued for the
lower-risk/already-proven approach rather than reintroducing the same
crawler-visibility question on the pages that matter most).

### 2c. CSS strategy: narrow `!important` hardening only, not a blanket reset

Unlike `Ahy_SmartSearchLuma`'s `all: revert` approach, `FalcoSense_Search`
was given **only** a narrow, targeted `!important` guard on the new
`#fs-ssr-grid` wrapper's box-model properties (`box-sizing`, `width`,
`max-width`, `margin`) — not a blanket reset. Reasoning: this module
*intentionally* shares the theme's Tailwind classes/colors; a blanket
reset would strip out the exact styling this module depends on rather than
protecting it. There was also no evidence of an existing CSS-leak bug here
(unlike the padding bug that originally motivated the `Ahy_SmartSearchLuma`
work) — the goal here was "don't let the *new* SSR markup regress," not
"fix a known leak."

**Portability caveat (discussed, not acted on)**: this module is NOT
portable to a different Magento site/theme as-is. It ships zero CSS of its
own — every visual style comes from Tailwind utility classes and custom
color tokens (`ahy-blue`, `ahy-red`, `ahy-bg`, etc.) that only exist
because the host theme's own `tailwind.config.js`
(`design/frontend/Ahy/Everest2/web/tailwind/tailwind.config.js`) defines
them and scans this module's templates as content. A different theme
without matching config, or a non-Tailwind theme entirely, would render
this module's grid completely unstyled. This is a known, accepted
limitation, not a bug — out of scope for the work done this session.

### 2d. Category SSR scope: every page/sort, not canonical-only like search

Search's SSR is scoped to the **canonical view only** — page 1, no
filters/sort/brand/bypass_spell. Any other URL state falls straight
through to Alpine's original client-side `fetch()`, unchanged.

Category's SSR, per the user's explicit instruction ("the category page is
main... every fucking time should come from the ssr"), renders
server-side for **every page number and sort order** the URL specifies —
not just the default view. This was actually straightforward to build
because:
- `category/results.phtml`'s own Alpine `init()` only ever reads `p` and
  `sort` from the URL to begin with — filters/price are pure post-load
  client-side state, never encoded in the URL at all. So there was no
  "filtered view" case for category SSR to need to skip the way search
  does — the URL genuinely only ever carries page/sort, and SSR now covers
  100% of what the URL can express.
- `PlpQuery`/`FalcoSensePlpProvider` already carried all the necessary
  fields generically (page, sort, category id/name) — no new data-layer
  work needed, just a category-specific branch.

---

## 3. What was actually built — file by file

### New/ported value objects (`Model/Plp/`)
- `PlpQuery.php` — immutable request descriptor (`contextType`, `storeId`,
  `page`, `perPage`, `sort`, `filters`, `priceMin/Max`, `categoryId`,
  `categoryName`, `searchQuery`). Already had `CONTEXT_CATEGORY`/
  `CONTEXT_SEARCH` and category fields built in from the start — no
  changes needed when category SSR was added later.
- `PlpResult.php`, `PlpItem.php`, `PlpFacet.php` — response value objects,
  `toArray()` shapes match exactly what Alpine's own `fetch()` success
  handler already expects (`{success, data, facets, pagination}`), so the
  embedded SSR payload needs zero new JS mapping logic on either page.

### `Service/Plp/FalcoSensePlpProvider.php` — the platform adapter
Originally search-only (`fetch()` directly called the platform for a
search query, hard-rejecting anything else). Refactored into:
- `fetch(PlpQuery $query)` — dispatcher, routes to `fetchCategory()` if
  `$query->isCategory()`, else `fetchSearch()`.
- `fetchSearch()` — original logic, unchanged. Params: `search_token`,
  `q`, `page`, `per_page`, `include_variants=1`. (`platform_store_id`
  deliberately omitted — same reasoning as the client JS:
  `getPlatformStoreId()`'s position-based calculation is wrong for
  single-store-view sites.)
- `fetchCategory()` — new. Params: `search_token`, `category` (name),
  `category_ids` (if truthy — scopes to the exact category when two
  categories share a name), `page`, `per_page`, `sort` (only sent if not
  `'relevance'`). No `include_variants` (category's own client `fetch()`
  doesn't send it either). Mirrors `category/results.phtml`'s client-side
  `fetch()` params exactly, so server and client stay in sync by
  construction.
- Both branches have `[SmartSearch][BENCH]` timing instrumentation — see
  §5 and §8.
- `mapResponse()`/`mapItem()`/`mapFacet()`/`resolveImageUrl()` are
  context-agnostic, shared by both branches unchanged.

### `Model/Plp/PageContext.php` — request → query
- `isSearchPage()` / `buildSearchQuery()` — detects
  `catalogsearch_result_index`/`fs_search_index`, returns a `PlpQuery` only
  for the canonical view (`isCanonicalRequest()` checks `p`, `brand`,
  `price_min`, `price_max`, `sort`, `bypass_spell` are all empty).
- `isCategoryPage()` / `buildCategoryQuery()` — **new**. Detects
  `catalog_category_view`, resolves the current category via
  `Magento\Catalog\Model\Layer\Resolver` (newly injected constructor
  dependency), builds a `PlpQuery` **unconditionally** (reads `p`/`sort`
  from the request, no canonical-only gate).

### `Block/Search.php` / `Block/Category.php`
Both expose `getPlpResult(): ?PlpResult` — memoized, calls
`PageContext::build*Query()` then `PlpDataProviderInterface::fetch()`,
returns `null` on any non-usable/non-applicable case (template falls back
to the original CSR behavior automatically). `Block/Category.php` needed
its `PageContext`/`PlpDataProviderInterface` dependencies added fresh (it
had neither before this session — was 100% CSR).

`Block/Search.php` additionally has a **temporary** A/B kill switch:
```php
private const AB_DISABLE_SSR = false; // see §8 — remove when done testing
```

### `search/results.phtml` (most heavily changed file, ~1200 lines)
1. **SSR grid + payload** (added early in the session): a `$renderSsrCard`
   closure builds plain-PHP card HTML (image, name, seller, price/deal
   logic) into `#fs-ssr-grid`, plus a `<script type="application/json"
   id="fs-ssr-payload">` with the full `PlpResult::toArray()` JSON. Alpine's
   `init()` seeds from this payload (skipping a live fetch) only for the
   canonical view; falls through to the original `fetch()` flow otherwise.
2. **Declarative Shadow DOM wrap** (added later, as the explicit
   experiment from §2b): the SSR grid + the entire
   `x-data="ahySearchResults(...)"` Alpine component are now both inside
   `<template shadowrootmode="open">`. Also added:
   - A `<link rel="stylesheet">` re-linking the theme's compiled
     `css/styles.css` inside the shadow root (host stylesheets can't
     cascade into a shadow tree — this is what lets the shared Tailwind
     classes still resolve).
   - A duplicate copy of the file's own `<style>` block placed inside the
     shadow root (same reasoning — light-DOM `<style>` tags don't reach
     into a shadow tree either). The original light-DOM copy was left in
     place too (harmless — becomes dead/no-op CSS for whatever moved into
     the shadow root, but still needed for anything that *didn't* move).
   - A standard, idempotent Declarative-Shadow-DOM fallback shim (plain
     `<script>`, promotes any leftover `<template shadowrootmode>` for
     browsers without native support; a safe no-op on browsers that
     already promoted it during parsing).
   - **3 broken DOM lookups fixed** — `document.getElementById(...)` calls
     for `fs-ssr-grid`/`fs-ssr-payload` (in `init()`) and
     `search-results-layout` (in `_scrollToResults()`) all rewritten to go
     through `this.$root.getRootNode()` (returns the `ShadowRoot`, which
     supports `getElementById` via the `DocumentOrShadowRoot` interface)
     instead of the top-level `document`, which can never see into a
     shadow tree.
   - The `alpine:initialized` fallback listener (pre-existing code, not
     newly added) rewritten to look inside
     `document.getElementById('fs-search-shadow-host').shadowRoot` instead
     of `document` directly.
3. **Layout glitch fix** (added last, see §6): `#fs-ssr-grid` was
   originally a bare `<div class="w-full">`, sibling to (not wrapped by)
   the real `ahy-filter-layout` flex container the Alpine grid renders
   inside alongside its filter sidebar. Fixed by wrapping the SSR grid in
   the identical `flex px-4 md:pr-0 lg:pl-6 pt-0 pb-12 ahy-filter-layout`
   structure with an empty, `aria-hidden` `<aside class="ahy-filter-sidebar">`
   so it occupies the same column width.

### `search/product-card-desktop.phtml` / `product-card-mobile.phtml`
One-line fix each, needed for the shadow DOM wrap: the "See Options"
configurable-product button's `$dispatch('ahy-cfg-modal-open', product)`
(Alpine's `$dispatch`, relies on event bubbling that may or may not cross
a shadow boundary depending on Alpine's internal implementation, unverified
locally) changed to `window.dispatchEvent(new CustomEvent('ahy-cfg-modal-open',
{detail: product, bubbles: true}))` — dispatched directly on `window`,
unaffected by shadow boundaries, matching the pattern this same codebase's
`html/header/product-card-desktop.phtml`/`product-card-mobile.phtml`
*already* used for the identical interaction (pre-existing prior art, not
invented for this task).

### `category/results.phtml` (716 lines originally, no SSR before this session)
Built from scratch, following the search pattern but adapted for category
per §2d:
1. Same `$renderSsrCard`/SSR-grid/payload pattern as search, but **no
   canonical gate** — `init()` always tries the payload first.
2. Same layout-glitch fix applied proactively (built correctly the first
   time here, since the bug was already understood from fixing search).
3. **No shadow DOM.**
4. `document.getElementById('fs-ssr-grid')` in `init()` — plain
   `document`, not `shadowRoot`-scoped, since there's no shadow boundary
   here.

### Layout XML — `cacheable="false"`
Both `catalogsearch_result_index.xml`/`fs_search_index.xml` (search) and
`catalog_category_view.xml` (category, added this session) mark every
block `cacheable="false"`. Necessary once a block renders real per-query
content server-side — without it, Magento's Full Page Cache would serve
whichever response happened to be cached first for a given URL to every
subsequent visitor of that URL, regardless of the current query/page/sort.
**This is a real, necessary fix, not precautionary** — see §6 for the
actual bug this prevents.

---

## 4. Benchmarking — full methodology and every data point recorded

### 4a. What's being measured, and how

Two independent measurement techniques were used, deliberately kept
separate to cross-check each other:

1. **In-app timing instrumentation** — `[SmartSearch][BENCH]` log lines in
   `FalcoSensePlpProvider.php`, using `microtime(true)` wrapped tightly
   around just the platform HTTP call (`PlatformHttpClient::getJson()`)
   and the response mapping. This isolates FalcoSense's own server-side
   cost from everything else Magento does on the same request. Read via:
   ```
   grep BENCH var/log/system.log | tail -n N
   ```
2. **Browser DevTools Network tab** — the *document* request's "Waiting
   for server response" (TTFB) time. This is the *combined* cost of
   Magento's own bootstrap/rendering **plus** the FalcoSense platform call
   (since SSR means the platform call happens inside that one server
   response), so it's a different, larger number than #1 by design — the
   gap between the two is exactly "how much is Magento vs. how much is
   FalcoSense."

### 4b. Search — recorded numbers

Exact log lines from this specific session were not preserved verbatim in
the retained context, but the established, repeatedly-confirmed range from
this session's testing was: **platform round-trip ≈56-130ms, mapping
overhead negligible (≤a few ms)**. This was cross-checked against a
same-server curl test (run directly from the dev2 box, not from any
sandbox) which landed in the same 60-95ms range — two independent methods
agreeing.

**A garbage-comparison mistake made and corrected during this exact
process, worth remembering**: an early attempt to replicate the CSR fetch
via curl from an unrelated sandbox environment used random/nonsense query
strings (to guarantee a fresh, uncached query), which triggered the
platform's slow "no results" code path (1.3-1.7s) — falsely making CSR
look far slower than SSR. Corrected by re-testing with real search terms,
and separately by having the user run the equivalent curl test **from the
dev2 server itself** for a fair, same-network-location comparison.

**A second methodology mistake, also corrected**: initially compared
SSR's *whole-page* TTFB (including ~1.1s of unrelated Magento bootstrap)
against CSR's *isolated fetch-only* time from the Network tab — not a fair
comparison (different things were being measured on each side). Resolved
by isolating both to their platform-round-trip-only component, which then
showed them to be roughly equivalent in raw speed — confirming SSR's real
benefit is eliminating the round trip's *network distance* cost for the
visitor, not making the platform itself respond faster.

**Production `everest.com` numbers** (the OLD, unmodified CSR-only
version, reported by the user from their own browser Network tab, for
context/comparison — NOT from this session's SSR work): first search for
"tshirt" showed 460.71ms "Waiting for server response" on the `products`
XHR; a second search showed 271.77ms. The drop between first and second is
TCP connection reuse (DNS/connection/SSL setup skipped on the second
request), not caching. This confirmed dev2's much slower baseline
(~800ms-1.7s) is a **dev2-specific infrastructure issue**, not something
caused by FalcoSense/SSR/CSR architecture — production is simply a
better-performing environment.

### 4c. Category — full raw data (this is the complete, exact log dump collected this session)

Collected via `grep BENCH var/log/system.log`, one real user manually
navigating between categories over roughly a 15-minute window on 2026-09-02
between 13:08 and 13:23 UTC:

| Time (UTC) | Category | Platform round-trip | Total provider time |
|---|---|---|---|
| 13:08:34 | Boating Gear (p1) | 96ms | 96ms |
| 13:08:42 | Boating Gear (p1) | 73ms | 74ms |
| 13:08:55 | Boating Gear (p1) | 70ms | 71ms |
| 13:09:51 | Boating Gear (p1) | 67ms | 68ms |
| 13:09:59 | Boating Gear (p1) | 75ms | 76ms |
| 13:10:04 | Boating Gear (p1) | 84ms | 86ms |
| 13:12:13 | Boating Gear (p1) | 88ms | 88ms |
| 13:22:04 | Boating Gear (p1) | 107ms | 111ms |
| 13:22:12 | Boating Gear (p1) | 131ms | 132ms |
| 13:22:17 | Boating Gear (p1) | 74ms | 76ms |
| 13:22:24 | Boating Gear (p1) | 68ms | 68ms |
| 13:22:30 | Boating Gear (p1) | 63ms | 63ms |
| 13:22:34 | Fishing Gear (p1) | 100ms | 120ms |
| 13:22:58 | Optics (p1) | 78ms | 94ms |
| 13:23:09 | Archery (p1) | 103ms | 106ms |
| 13:23:18 | Optics (p1) | 93ms | 94ms |
| 13:23:23 | Hunting Gear (p1) | 96ms | 96ms |
| 13:23:29 | Hunting Gear (p1) | 80ms | 81ms |

**Range: 63-132ms. Mean roughly ~85ms.** No spikes, no correlation with
which category, no correlation with how slow the *overall* page load was
that particular time.

**Corresponding DevTools document TTFB, same session** (from screenshots,
Network tab, "Waiting for server response" on the `*.html` document
request itself):
- `hunting-gear.html`: **791.96ms** (+ 202.06ms content download = 995.83ms
  total finish), and separately **995.83ms**/**995ms**-ish range reported
  by the user as sometimes reaching 2-3s on some category clicks.
- `boating-gear.html`: 827.36ms (+199.51ms content download = 1.03s total)
  on one load, 1.51s document time on a later reload of the same page.

**Conclusion drawn from this data** (stated directly to the user, holds up
under the math): taking `hunting-gear.html`'s 791.96ms against the
closest-in-time BENCH reading (~80-96ms), **FalcoSense's own contribution
is ~11%, Magento's own bootstrap/rendering is ~89%** of that request. The
2-3s spikes the user observed on some category clicks never showed up in
the BENCH numbers at all (which stayed in the normal 63-132ms band
throughout) — meaning those spikes are ~100% Magento-side, not
FalcoSense-side.

### 4d. Honest assessment of this methodology's validity (given directly to the user, worth preserving)

**Strengths**: measures the right boundary (wraps only the platform call,
nothing else bleeds in); runs on the same machine as the real request
(not a separate sandbox, avoiding the earlier network-distance mistake);
`microtime(true)` is accurate; corroborated by an independent same-server
curl test that landed in the same range; the tight, consistent clustering
of ~20 samples across 6 different categories is itself evidence against a
noisy/flawed measurement.

**Limitations, stated plainly**: this is a **single-user, light/no-
concurrency sample over ~15 minutes**, not a production load test — it
says nothing about behavior under concurrent traffic contention. ~20
samples is enough to establish a typical range, not enough to rule out
rare tail-latency outliers. The platform call has a configured timeout
(`getPlpPlatformTimeoutMs()`, default 500ms per `Helper/Data.php`), so this
measurement structurally cannot show worst-case behavior if the platform
ever truly stalled — only how it performs when it succeeds normally. It's
self-instrumented (the code measuring itself), mitigated by the
independent curl cross-check but worth naming as a category of limitation.

**Correct way to describe this methodology, verbatim as given to the
user**: *"In-process server-side timing instrumentation of the production
code path, sampled under light/single-user load — not a load test."*

### 4e. What was explicitly NOT done

- No `Server-Timing` HTTP header was added (discussed as **Option A** for
  making FalcoSense's contribution visible directly in DevTools' Network
  Timing panel, on the same real SSR request, rather than requiring a
  separate log-file check — proposed, not yet built, no decision made by
  the user on whether to build it).
- No production `everest.com` load-mode/opcache/PHP-FPM investigation was
  done — the ~700ms-3s Magento bootstrap cost on **dev2 specifically** was
  identified as the dominant remaining cost, and flagged as a separate
  infrastructure question outside this module's code, but not
  investigated further this session.

---

## 5. Bugs found and fixed this session (chronological)

1. **Stale FPC serving old CSR response on the native search route** —
   `catalogsearch_result_index.xml` was missing `cacheable="false"`
   (`fs_search_index.xml` already had it). Real bug, fixed in `b85892d`.
   **Caveat**: a later full re-audit found the header search form actually
   submits to `/fs/search` directly (not `/catalogsearch/result/`), so
   this fix, while real and correct to keep, was likely NOT the actual
   root cause of an earlier-reported "search box sometimes shows old CSR
   behavior" symptom. That original symptom was never conclusively
   reproduced after this fix — most likely explanation is browser/DevTools
   testing artifacts (stale "Preserve Log," testing across different
   queries without realizing it), not a real remaining server bug, but
   this was never 100% confirmed via a final clean repro test.
2. **PHP/JS canonical-view check mismatch** — the JS-side check for
   "should I treat this as the default view" included `bypass_spell`, the
   PHP-side `PageContext::isCanonicalRequest()` didn't. Fixed in `49b14a9`.
3. **SSR grid layout glitch** (this session, most recently) — see §6.

## 6. The layout glitch — full detail (most recent fix, worth understanding deeply)

**Symptom, in the user's own words**: *"products layout first is seen full
screen and huge boxes and no filters... and then normal plp"* — visible
for roughly the same ~1 second window as the SSR→Alpine handoff.

**Root cause**: the real Alpine-rendered grid lives inside a flex
container (`id="search-results-layout"` / `id="category-results-layout"`,
class `flex px-4 md:pr-0 lg:pl-6 pt-0 pb-12 ahy-filter-layout`) alongside
an `<aside class="ahy-filter-sidebar">` that takes a fixed width
(200px/285px depending on breakpoint, set via `!important` media-query
rules). The SSR grid (`#fs-ssr-grid`), however, was built as a bare
`<div class="w-full">` **outside** that flex structure entirely — with no
sidebar to share width with, `width: 100%` meant 100% of the whole content
column, not 100% of the space next to a sidebar. Result: for the SSR-only
window, cards rendered oversized/full-bleed with no visible filter column,
then visibly resized down the moment Alpine replaced it with the correctly
-wrapped version.

**Fix**: wrap `#fs-ssr-grid`'s contents in the identical flex structure —
same `ahy-filter-layout` classes, plus an empty, `aria-hidden="true"`
`<aside class="hidden md:block ahy-filter-sidebar">` (no filter content
needed server-side, just needs to occupy the same width) — so the grid
column sizes identically in both the SSR and Alpine-rendered states.
Applied to both `search/results.phtml` and `category/results.phtml`
(same underlying bug existed on both, just less noticed on search).

**Not yet re-verified in the browser after this exact fix** — was
committed (`7b040a9`) and deployment instructions were given, but no
follow-up confirmation from the user that the visual glitch is actually
gone was recorded before this session ended. **First thing to check in the
next session if picking this up.**

---

## 7. Verification performed and confirmed working

- **Search + Shadow DOM + SSR, all together**: confirmed via direct
  browser testing (not just code reading) — View Source showed the raw
  `<template shadowrootmode="open">` tag containing real product HTML and
  a `#fs-ssr-payload` script with `"success":true` and real product/
  variant data; DevTools Elements panel showed a genuine `▼ #shadow-root
  (open)` node under `#fs-search-shadow-host`, containing the `<link>`,
  `<style>`, `#fs-ssr-grid` (before Alpine removed it), and the
  `x-data="ahySearchResults(...)"` component — all nested correctly. This
  was the conclusive proof point after an earlier false start where the
  live server actually had `AB_DISABLE_SSR=true` and the "working" shadow
  root screenshot was actually only proving shadow-DOM-wraps-CSR, not
  shadow-DOM-wraps-SSR (see §0's discrepancy note) — re-verified correctly
  after that was caught and fixed.
- **Category SSR, every page/sort**: confirmed via View Source on
  `boating-gear.html` and `hunting-gear.html` showing real `#fs-ssr-grid`
  content and a populated `#fs-ssr-payload` with real product data; no
  separate `products` XHR visible in the Network tab (confirming Alpine
  seeded from the payload instead of fetching) on both a plain load and
  after clicking between several different categories.
- **Benchmarking**: see §4 in full.

## 8. Explicitly still open — do not forget these

- **`AB_DISABLE_SSR` kill switch in `Block/Search.php`** — currently
  `false` (SSR on), but the constant and its `if` check are still in the
  code. Was for A/B benchmarking only. The user was asked "want me to
  clean this up now?" and had not answered before this session ended.
  **Ask again, or just remove it if picking this up and SSR is confirmed
  staying on.**
- **`[SmartSearch][BENCH]` logging in `FalcoSensePlpProvider.php`** —
  still live in both `fetchSearch()` and `fetchCategory()`. Same
  situation — temporary instrumentation, proven useful, never removed,
  same unanswered cleanup question.
- **The SSR-grid layout-glitch fix (§6) has not been re-verified in the
  browser** after the fix commit — do this first if resuming.
- **Magento's own ~700ms-3s bootstrap overhead on dev2** — confirmed to
  be the dominant cost, confirmed NOT to be FalcoSense's fault, but not
  yet investigated (deploy mode, opcache, PHP-FPM config were named as the
  likely areas, per this exact module's own code comments referencing a
  past OPcache incident — see `Block/Search.php`'s `getCustomerGeoCountry()`
  docblock for that story). The user was offered this as a next step and
  had not yet said yes/no before this session ended.
- **`Server-Timing` response header** — proposed (§4e) as a way to show
  FalcoSense's isolated contribution directly in DevTools on the real SSR
  request, instead of requiring a server log check. Not built, no decision
  made.
- **Shadow DOM was never extended to category**, and per §2b, this was a
  deliberate choice, not an oversight — don't add it without the user
  explicitly asking, since it reopens the AEO/crawler-visibility risk on
  the pages the user called "main."
- **Production `everest.com` has NOT been touched** — every deploy this
  session was to **dev2 only**. The screenshots referencing production
  CSR numbers (§4b) were the user checking the *existing, unmodified*
  production site for comparison context, not anything this session
  deployed to.
- **No formal pros/cons write-up of the shadow DOM experiment's outcome
  was requested or produced** after it was proven working — the user
  moved on to category SSR before circling back to that.

---

## 9. Deploy reference — what commands are needed for which kind of change

Established and confirmed working this session:

- **Template-only changes** (`.phtml` files, no PHP class/constructor
  changes): upload the file(s), then just
  ```
  php bin/magento cache:flush
  ```
- **PHP class/constructor changes** (e.g. adding a new constructor
  dependency to `PageContext`/`Block\Category`, as happened when category
  SSR was added): upload the file(s), then
  ```
  php bin/magento setup:di:compile
  php bin/magento cache:flush
  ```
  **Known gotcha, documented in this exact codebase from a past real
  incident** (see `Block/Search.php`'s `getCustomerGeoCountry()` docblock):
  a constructor-injection change can hit a **stale OPcache** serving old
  interceptor bytecode after `di:compile` regenerates it, with no
  documented way to force a PHP-FPM restart on this server. If a
  constructor-signature change causes a fatal error or blank page after
  running both commands above, check `var/log/exception.log` for a
  too-few-arguments/missing-argument error — that's the signature of this
  exact issue recurring.
- **Layout XML changes** (`cacheable="false"` additions, block wiring):
  covered by `cache:flush` alone, no compile needed.
- `setup:upgrade` and `setup:static-content:deploy -f` were **not needed**
  for anything done this session (no DB schema changes, no new modules
  registered, no CSS/JS static assets touched — the shadow DOM work reused
  the theme's *existing* compiled `styles.css` via a `<link>`, never
  modified it).

---

## 10. Full file inventory (everything touched across this whole SSR project)

```
code/FalcoSense/Search/Api/PlpDataProviderInterface.php
code/FalcoSense/Search/Block/Category.php
code/FalcoSense/Search/Block/Search.php
code/FalcoSense/Search/Model/Plp/PageContext.php
code/FalcoSense/Search/Model/Plp/PlpFacet.php
code/FalcoSense/Search/Model/Plp/PlpItem.php
code/FalcoSense/Search/Model/Plp/PlpQuery.php
code/FalcoSense/Search/Model/Plp/PlpResult.php
code/FalcoSense/Search/Service/Plp/FalcoSensePlpProvider.php
code/FalcoSense/Search/Service/Plp/PlatformHttpClient.php
code/FalcoSense/Search/Service/Plp/PlatformRequestException.php
code/FalcoSense/Search/etc/di.xml   (PlpDataProviderInterface preference)
code/FalcoSense/Search/view/frontend/layout/catalog_category_view.xml
code/FalcoSense/Search/view/frontend/layout/catalogsearch_result_index.xml
code/FalcoSense/Search/view/frontend/layout/fs_search_index.xml
code/FalcoSense/Search/view/frontend/templates/category/results.phtml
code/FalcoSense/Search/view/frontend/templates/search/results.phtml
code/FalcoSense/Search/view/frontend/templates/search/product-card-desktop.phtml
code/FalcoSense/Search/view/frontend/templates/search/product-card-mobile.phtml
code/FalcoSense/Search/Helper/Data.php   (getPlpPlatformTimeoutMs, XML_PATH_PLP_TIMEOUT_MS)
```

Not touched, deliberately: `Controller/Result/Index.php`,
`Controller/Category`-equivalent (there isn't one — category dispatches
through Magento's own native controller, FalcoSense only hooks in via
layout XML), `Service/SearchTokenService.php`,
`category/product-card-desktop.phtml`/`product-card-mobile.phtml` (their
`$dispatch('ahy-cfg-modal-open', ...)` calls were left as-is since category
has no shadow DOM boundary for them to need to cross).
