# FalcoSense_Search — Complete Knowledge Transfer (KT) & SDLC Document

**Module:** `FalcoSense_Search`
**Location:** `code/FalcoSense/Search` (deployed as `app/code/FalcoSense/Search`)
**Host application:** Magento 2 Open Source / Adobe Commerce, Hyvä theme (`Ahy/Everest2`, parent `Hyva/default`)
**Live on:** `everest.com` (production) and `dev2.everest.com` (development)
**Document written:** 2026-09-08
**Document status:** Verified against the source tree file-by-file on the date above. Every claim in this document cites the file and line it came from so you can check it yourself.

---

## 0. How to read this document

This document is written to be read by four different people. You do not need to read all of it.

| If you are… | Read these sections | Skip |
|---|---|---|
| **Non-technical** (manager, product owner, client) | §1, §2, §3.1, §6.1, §7.1, §8.1, §9.1, §18.1, §18.4, §21 (Glossary) | Everything with code in it |
| **Fresher / new developer (day 1)** | §1 → §5 in order, then §6, then §21 (Glossary). Then pick one feature and read its section. | §12–§15 until you've shipped one change |
| **Experienced developer (scale 1–3)** | §3 → §10, then §16, §17, §20 | §1, §21 |
| **Tech Lead / Architect** | §2, §3, §7, §8, §17, §18, §19, §20 | §21 |
| **Anyone about to install this on a new site** | §18 first, then §5, then §3 | — |

**Conventions used in this document**

- `File/Path.php:123` means "file `File/Path.php`, line 123". All paths are relative to the module root (`code/FalcoSense/Search/`) unless they start with `code/` or `design/`.
- **WHAT / WHY / HOW / WHERE** blocks are used for every major concept, because that is what this document was commissioned to deliver.
- ⚠️ marks a real, currently-live problem or footgun.
- 🧊 marks dead code — present in the repo, not executed.
- Text in *italics* inside a quote block is a direct quotation from a code comment. These comments are unusually good in this codebase and often contain the only record of *why* something is the way it is.

---

## 1. Executive summary — what this module is, in plain English

### 1.1 The business problem

A Magento store's built-in product search is weak. It is a keyword matcher bolted onto a relational database (or, in newer versions, an OpenSearch/Elasticsearch index that Magento itself manages). It struggles with:

- typos ("tshrit" → nothing found),
- synonyms and intent ("rain jacket" vs "waterproof coat"),
- relevance ranking (best-selling, in-stock, high-margin products should surface first),
- speed at catalogue scale (this store has **150,000+ products**),
- personalisation (a shopper in Texas may want different results than one in Alaska).

Meanwhile, the **category pages** (also called PLPs — Product Listing Pages) have the same problem in a different costume: Magento builds them by running a large database query and rendering it server-side, which is slow, and offers only crude filtering.

### 1.2 What FalcoSense is

**FalcoSense is an external, hosted (SaaS) search-and-discovery platform.** It is not part of Magento. It is a separate service reachable over HTTPS that:

1. **Stores a copy of the catalogue.** Magento continuously pushes product data to it (name, SKU, price, stock, images, brand, categories, variants).
2. **Answers search and listing queries.** Given a search term or a category, it returns the right products in the right order, plus the filter options ("facets") that make sense for that result set.
3. **Learns from behaviour.** Magento reports what shoppers view, click, add to cart, wishlist and buy, so the platform can improve ranking over time.

### 1.3 What this Magento module does

`FalcoSense_Search` is the **glue** between Magento and FalcoSense. It has exactly three jobs:

| Job | Direction | Plain English |
|---|---|---|
| **Ingest / sync** | Magento → FalcoSense | "Here is my catalogue, and here is every change to it as it happens." |
| **Query / render** | Magento or browser → FalcoSense | "A shopper searched for X / opened category Y — what should I show them?" — then draw it on screen. |
| **Analytics** | Magento → FalcoSense | "Here is what shoppers actually did." |

It also **replaces large parts of the storefront UI**: the header search box becomes a full-screen live-search experience, the search results page is entirely custom, and category pages no longer use Magento's product list at all.

### 1.4 Why it is architecturally unusual

Three things make this module harder to understand than a typical Magento extension, and they are the three things this document spends the most time on:

1. **It renders the same product grid in five different places, in two different languages.** The card markup exists as PHP (server-side) *and* as Alpine.js templates (client-side), for search, category, header overlay and sliders. See §6.3.
2. **It does real Server-Side Rendering (SSR)** — it calls the FalcoSense API from PHP, before the page is sent, and then hands the same data to the JavaScript so the JavaScript doesn't call the API again. See §7.
3. **The search results page is wrapped in a Shadow DOM boundary** using Declarative Shadow DOM, which changes the rules for CSS and for `document.getElementById()`. See §8.

### 1.5 Scale of the codebase

Measured on 2026-09-08:

| Metric | Count |
|---|---|
| Live files (excluding backups) | **134** |
| **Backup (`.bak*`) files** | **214** ⚠️ |
| PHP classes | 86 |
| PHTML templates | 27 |
| XML config files | 20 |
| Total live lines of code (php + phtml + xml) | **~18,485** |
| Disk size of module | 7.2 MB (of which ~5.8 MB is backup files) ⚠️ |

⚠️ **There are more backup files than real files in this module.** Every `*.bak_*` file is a manual snapshot taken before an edit (`Search.php.bak_20260723T155337`, `system.xml.bak_v5_20260829`, and so on). They are tracked in git, they are deployed to the server, they are scanned by the theme's Tailwind build, and they make every `grep` and every file search in this module misleading. Dealing with this is item #1 in the backlog (§20).

---

## 2. ⚠️ CRITICAL: there are TWO FalcoSense modules. Know which one you are looking at.

This is the single most common way to waste a day on this codebase.

| | `FalcoSense_Search` | `Ahy_SmartSearchLuma` |
|---|---|---|
| **Repo / path** | `dev2-app` → `code/FalcoSense/Search` | `falcosense-shadowdom-module` (standalone git repo) |
| **Enabled in `etc/config.php`?** | **`'FalcoSense_Search' => 1`** ✅ | `'Ahy_SmartSearchLuma' => 0` ❌ |
| **Running on production `everest.com`?** | **Yes** | No |
| **This document describes…** | **This one** | Only §8.8, for contrast |
| **Rendering** | Alpine.js inline in `.phtml`, plus PHP SSR | ES modules under `view/frontend/web/js/widget/` |
| **CSS strategy** | **Shares** the host theme's Tailwind classes and `ahy-*` colour tokens. No CSS of its own. | **Isolates** from the host theme: `:host { all: initial }`, ships its own `BASE_CSS` |
| **Shadow DOM** | Declarative only, **search results page only** | Everywhere, imperative + declarative ("detect-then-attach") |
| **Maturity** | Backend (sync, admin config, logging) is mature; frontend is organically grown | Frontend architecture is cleaner; backend is a subset |

Verification of the above (do this yourself if in doubt):

```bash
grep -n "FalcoSense_Search\|Ahy_SmartSearchLuma" etc/config.php
# 365:        'Ahy_SmartSearchLuma' => 0,
# 391:        'FalcoSense_Search' => 1,
```

> From `code/FalcoSense/Search/Model/Plp/PlpQuery.php:12`:
> *"Ported from Ahy_SmartSearchLuma's Model/Plp/PlpQuery.php (namespace swap only) — this value object carries no platform-specific wire-format assumptions, so it's directly reusable as-is."*

Several files in `FalcoSense_Search` are direct ports from `Ahy_SmartSearchLuma` and say so in their docblocks: `PlpQuery`, `PlpResult`, `PlpItem`, `PlpFacet`, `Service/Plp/PlatformHttpClient.php`. That is why the two modules feel related — the SSR data layer genuinely is shared ancestry. Everything else is independent.

---

## 3. System context and architecture

### 3.1 The players (non-technical)

```
   ┌──────────────┐        ┌───────────────────────────────┐        ┌──────────────────┐
   │              │        │                               │        │                  │
   │   Shopper's  │◄──────►│      Magento (everest.com)    │◄──────►│   FalcoSense     │
   │   Browser    │  HTML  │      + FalcoSense_Search      │  HTTPS │   Platform (SaaS)│
   │              │  JSON  │                               │  JSON  │                  │
   └──────┬───────┘        └───────────────┬───────────────┘        └────────┬─────────┘
          │                                │                                 │
          │  The browser ALSO talks        │  Magento owns: cart, checkout,  │  FalcoSense owns:
          │  DIRECTLY to FalcoSense        │  customers, orders, prices,     │  the search index,
          │  for live search results       │  the product database           │  relevance ranking,
          └───────────────────────────────►│                                 │  facets, suggestions,
                                           │                                 │  behavioural analytics
                                           └─────────────────────────────────┘
```

**The key thing a non-technical reader should take away:** the shopper's browser talks to FalcoSense *directly* for most product listings. Magento is not in the middle of every search. This is why search feels fast — but it also means if FalcoSense is down, product grids can go blank even though Magento itself is perfectly healthy. §7.7 explains what protections exist.

### 3.2 The two independent data flows

Everything in this module belongs to one of two flows. They share almost no code. Understanding this split makes the file structure obvious.

```
FLOW A — INGEST (write path).  "Keep FalcoSense's copy of the catalogue current."
─────────────────────────────────────────────────────────────────────────────────
 Product saved in admin ─┐
 Stock level changes    ─┤
 Product deleted        ─┼──► Observer ──► AttributeChangeDetector ──► RabbitMQ queue
 Cron (scheduled)       ─┤                  (is this change worth        │
 CLI `sync:full`        ─┤                   sending?)                   ▼
 Admin "Sync All" button ┘                                        WebhookConsumer
                                                                         │
                                                                         ▼
                                                        ProductSyncService ──HTTP POST──► FalcoSense
                                                                                          /api/v1/ingest/*
 Covered in: §12

FLOW B — QUERY (read path).  "Show the shopper products."
─────────────────────────────────────────────────────────────────────────────────
 (B1) Server-side / SSR:
   HTTP request ──► Magento router ──► Block\Search or Block\Category
                                            │
                                            ├─► PageContext        (is this a page we handle? build a PlpQuery)
                                            ├─► SearchTokenService (get a short-lived token)
                                            └─► FalcoSensePlpProvider ──HTTP GET──► FalcoSense /api/v1/products
                                                        │                             (500 ms budget)
                                                        ▼
                                                    PlpResult ──► results.phtml renders real HTML cards
                                                                 + embeds the same data as JSON

 (B2) Client-side / CSR:
   Alpine.js component in the browser ──HTTP GET──► FalcoSense /api/v1/products
                                                    (for filters, sorting, pages 2+, live typing)

 Covered in: §6 (rendering), §7 (SSR), §8 (Shadow DOM), §9 (search), §10 (category)
```

### 3.3 Every external endpoint this module talks to

Complete inventory, gathered by grepping the live source. The base URL for all of these is derived from one admin setting (`smart_search/general/endpoint_url`) by `Helper\Data::buildPlatformUrl()` (`Helper/Data.php:116`), which keeps only the scheme, host and port and replaces the path.

| Endpoint | Called from | Verb | Auth | Purpose |
|---|---|---|---|---|
| `/api/v1/auth/token` | PHP — `SearchTokenService::fetchFromPlatform()` (`Service/SearchTokenService.php:83`) | POST | `X-Api-Key:` header | Exchange the secret API key for a short-lived public search token |
| `/api/v1/products` | **PHP (SSR)** — `FalcoSensePlpProvider` (`Service/Plp/FalcoSensePlpProvider.php:63`, `:122`) **and browser (CSR)** — every Alpine component | GET | `search_token` query param | The main workhorse: search results, category listings, facets, pagination |
| `/api/v1/product` | Browser — `modal/config-modal.phtml:43` | GET | `search_token` | Full detail for one configurable product (variant list, prices) |
| `/api/v1/suggest` | Browser — `html/header/search-form.phtml:313`; PHP — `Controller/Suggest/Index.php:34` 🧊 | GET | `search_token` | Search-term suggestions / "popular searches" |
| `/api/v1/analytics/search` | Browser — `search/results.phtml:830` | POST | `search_token` in body | Report a search: query, result count, response time |
| `/api/v1/events` | Browser (`sendBeacon`, `search/results.phtml:841`) and PHP (`Helper\Data::getEventsEndpointUrl()`, `Helper/Data.php:107`) | POST | `search_token` / API key | Behavioural events: clicks, add-to-cart, wishlist, purchase |
| `/api/v1/ingest/...` | PHP — `ProductSyncService` | POST | `X-Api-Key:` header | Push product data (§12) |

**Internal Magento endpoints this module exposes** (its own controllers):

| URL | Class | Purpose |
|---|---|---|
| `/fs/search?q=…` | `Controller\Search\Index` | The custom search results page (short, pretty URL) |
| `/catalogsearch/result/?q=…` | `Controller\Result\Index` (**overrides Magento's own**) | The native search URL, hijacked |
| `/smartsearch/search/token` | `Controller\Search\Token` | AJAX: hand the browser a fresh search token |
| `/smartsearch/configurable/options` | `Controller\Configurable\Options` | AJAX: real Magento variant attributes + live stock |
| `/smartsearch/suggest` | `Controller\Suggest\Index` 🧊 | AJAX suggest proxy — **no caller exists** |
| `/smartsearch/sync/*` (admin) | `Controller\Adminhtml\Sync\{FullSync,Status,StopSync}` | Admin sync buttons (§12) |

**⚠️ External endpoints from a *different* module that this module hard-depends on:**

| URL | Provided by | Used by |
|---|---|---|
| `/ahy_themecustomization/index/mediaGallery` | `Ahy_ThemeCustomization` | `modal/config-modal.phtml` (5 call sites) — product image galleries |
| `/ahy_themecustomization/index/resolveSuperAttribute` | `Ahy_ThemeCustomization` | `modal/config-modal.phtml` (3 call sites) — resolve chosen variant |

This is a hidden coupling: `etc/module.xml` does **not** declare `Ahy_ThemeCustomization` as a dependency, but the configurable-product modal breaks without it. This matters a lot for §18 (porting to another site).

---

## 4. File and folder structure — what everything is and does

### 4.1 Magento module anatomy, for a fresher

A Magento 2 module is a folder of conventions. Magento discovers behaviour by *where a file is*, not by an import graph. If you have never worked on Magento, learn these eight rules and the tree below becomes readable:

| Folder | Convention | Analogy |
|---|---|---|
| `registration.php` | Tells Magento "a module called X lives here" | `package.json` name field |
| `etc/*.xml` | Declarative configuration. Magento reads these at boot and caches them. | Framework config files |
| `etc/di.xml` | **Dependency Injection**: "when someone asks for interface A, give them class B" (`preference`), "when constructing class C, pass it this specific logger" (`type`/`arguments`) | An IoC container config |
| `etc/frontend/`, `etc/adminhtml/` | Same file names, but scoped to only the storefront or only the admin panel | Environment-specific config |
| `Block/` | A PHP class that prepares data **for one template**. The template's `$block` variable *is* an instance of this class. | A view-model / controller-for-a-widget |
| `view/frontend/layout/*.xml` | **Layout XML**: which blocks appear on which page, in which container, with which template. The file *name* is the page it applies to. | A routing table for the DOM |
| `view/frontend/templates/*.phtml` | The actual HTML, in PHP | A template file |
| `Observer/`, `Plugin/` | Hooks: "run my code when Magento fires event X" / "wrap Magento's method Y" | Event listeners / middleware |
| `Service/`, `Model/`, `Helper/` | Plain PHP. No framework magic. `Helper` is legacy-flavoured; `Service` is the modern choice. | Business logic |

**The single most important rule for this module:** the *file name* of a layout XML file is the page it targets. `view/frontend/layout/catalog_category_view.xml` means "apply this to the category page", because `catalog_category_view` is Magento's internal name (the "full action name") for that page. This is how the module attaches itself to pages it does not own.

### 4.2 The annotated tree

Only **live** files are listed. All 214 `.bak*` files are omitted; they are noise (§20, item 1).

```
code/FalcoSense/Search/
│
├── registration.php ──────────── Registers the module as `FalcoSense_Search`.
│
├── etc/                          ◄── DECLARATIVE WIRING. Read this folder first.
│   ├── module.xml ────────────── Name + setup_version 1.0.5 + load order (`sequence`:
│   │                              Magento_MessageQueue, Magento_CatalogInventory).
│   ├── di.xml ────────────────── The nerve centre (105 lines). Four jobs:
│   │                              (1) registers 2 CLI commands
│   │                              (2) 3 `preference` overrides — see §5.2 (IMPORTANT)
│   │                              (3) 3 virtualType loggers → dedicated log files
│   │                              (4) injects those loggers into specific classes
│   ├── config.xml ────────────── Default values for 16 config paths. §5.5.
│   ├── acl.xml ───────────────── Admin permission resource `FalcoSense_Search::config`.
│   ├── events.xml ────────────── Global (all-area) event → observer bindings. §12/§13.
│   ├── crontab.xml ───────────── Scheduled jobs. §12.
│   ├── communication.xml    ┐
│   ├── queue_publisher.xml  ├─── RabbitMQ / MessageQueue topology for async sync. §12.
│   ├── queue_consumer.xml   │
│   ├── queue_topology.xml   ┘
│   ├── frontend/
│   │   ├── routes.xml ───────── Defines 3 URL routes: `smartsearch`, `catalogsearch`
│   │   │                         (an OVERRIDE of Magento's own), and `fs`. §5.3.
│   │   └── events.xml ───────── Storefront-only observers (analytics). §13.
│   └── adminhtml/
│       ├── routes.xml ───────── Admin route for the sync AJAX controllers.
│       └── system.xml ───────── The Stores ▸ Configuration ▸ Ahy ▸ FalcoSense screen. §14.
│
├── Api/                          ◄── INTERFACES (the "ports" of the architecture)
│   ├── PlpDataProviderInterface.php ── The single seam through which SSR gets its data.
│   │                                    Contract: "MUST NOT throw." §7.2.
│   └── Data/WebhookMessageInterface.php ── Queue message shape. §12.
│
├── Block/                        ◄── VIEW MODELS (one per template family)
│   ├── Search.php ───────────── For search/results.phtml. Exposes API URLs, the search
│   │                             token, customer geo, and getPlpResult() (the SSR entry
│   │                             point). Contains the AB_DISABLE_SSR kill switch. §7.
│   ├── Category.php ─────────── Same idea for the category page. §10.
│   ├── ProductCard.php ──────── Tiny: exposes admin style config to card templates. §14.
│   ├── HeaderSearchForm.php ─── Exists ONLY to hard-code getTemplate(), to win a fight
│   │                             with the Hyvä theme's layout merge. Read its docblock.
│   ├── GeoConsent.php ───────── Geo/consent banner. §13.
│   ├── Collection.php ───────── Curated "collection" widget. §11.
│   ├── Slider/Products.php ──── Product slider widget (CMS-placed). §11.
│   ├── Slider/Collection.php ── Collection slider variant. §11.
│   └── Adminhtml/System/Config/
│       ├── SyncAllButton.php ── "Sync All" button + its JS. §12.
│       ├── SyncStatus.php ───── Live sync status readout. §12.
│       ├── CardPreview.php ──── Renders a live preview of the product card in admin. §14.
│       ├── VersionInfo.php ──── Renders a logo. ⚠️ Hard-codes a dev2.everest.com image URL.
│       └── SliderInfo.php ───── 🧊 ORPHAN: not referenced by system.xml. §20.
│
├── Controller/                   ◄── HTTP ENTRY POINTS
│   ├── Result/Index.php ─────── Replaces Magento's search controller to avoid loading a
│   │                             150k-row product collection. Sets a title, returns layout.
│   ├── Search/Index.php ─────── The `/fs/search` page. ⚠️ Has 3 unused constructor deps
│   │                             and an unused LOG_FILE constant.
│   ├── Search/Token.php ─────── JSON: fresh search token for the browser. §9.3.
│   ├── Configurable/Options.php  JSON: REAL Magento variant attributes + stock. Its
│   │                             docblock explains the two broken things it replaced. §9.8.
│   ├── Suggest/Index.php ────── 🧊 DEAD (no caller) and ⚠️ sets CURLOPT_SSL_VERIFYPEER=false.
│   └── Adminhtml/Sync/{FullSync,Status,StopSync}.php ── Admin sync AJAX. §12.
│
├── Model/
│   ├── Plp/                      ◄── THE SSR DATA LAYER (immutable value objects)
│   │   ├── PageContext.php ──── "Is this request a search page or a category page, and
│   │   │                         what exactly is being asked for?" → builds a PlpQuery. §7.4.
│   │   ├── PlpQuery.php ─────── Immutable request descriptor (page, sort, filters, …).
│   │   ├── PlpResult.php ────── Immutable response (items, facets, total). Has the
│   │   │                         all-important isUsable() / unavailable() semantics.
│   │   ├── PlpItem.php ──────── One product card's data. toArray() matches the JS exactly.
│   │   └── PlpFacet.php ─────── One filter group.
│   ├── StyleConfig/Reader.php ── Reads admin style config. §14.
│   ├── Config/Backend/*.php ──── Save-time handlers for style fields. §14.
│   ├── Config/Source/*.php ───── Dropdown option sources. §14.
│   ├── AttributeChangeDetector.php ── "Did this product change in a way worth syncing?" §12.
│   ├── Webhook*.php, FullSync*.php ── Queue publishers/consumers. §12.
│   └── Cart/ImageProvider.php ── Overrides Magento's cart image logic (a `preference`). §15.
│
├── Service/                      ◄── BUSINESS LOGIC
│   ├── Plp/
│   │   ├── FalcoSensePlpProvider.php ── The SSR adapter. Calls the platform, maps the
│   │   │                                response, never throws. §7.2. ⚠️ Contains live
│   │   │                                [BENCH] instrumentation.
│   │   ├── PlatformHttpClient.php ───── The ONLY HTTP client with millisecond timeouts.
│   │   │                                Explains in its docblock why it isn't Magento's. §7.
│   │   └── PlatformRequestException.php ── Never escapes the service layer.
│   ├── SearchTokenService.php ── API key → short-lived token, cached in /tmp. §9.3.
│   ├── ProductSyncService.php ── 997 lines. The heart of the ingest pipeline. §12.
│   ├── FullSyncService.php, SyncLockManager.php, DisabledParentResolver.php,
│   │   DuplicateSkuResolver.php ────── Full-sync orchestration and edge cases. §12.
│   ├── CustomerEventService.php ────── Behavioural event submission. §13.
│   ├── ImageCompressionEngine.php, ProductImageCompressionService.php ── §15.
│
├── Observer/                     ◄── EVENT HOOKS (15 files) — §12 (sync) and §13 (analytics)
├── Cron/                         ◄── ProductSync.php (§12), ImageCompress.php (§15)
├── Console/Command/              ◄── FullSyncCommand.php (§12), ImageCompressCommand.php (§15)
├── Logger/                       ◄── 3 loggers + 5 handlers → 5 dedicated log files. §16.
├── Helper/
│   ├── Data.php ─────────────── ALL config access + buildPlatformUrl(). Read this early.
│   └── ProductImage.php ─────── Image URL resolution. §15.
├── Setup/                        ◄── UpgradeSchema.php (DB tables), UpgradeData.php. §12.
├── ViewModel/StyleConfig.php ─── Style config for templates that aren't ProductCard. §14.
│
└── view/
    ├── adminhtml/web/images/smart-search-banner.png
    └── frontend/
        ├── Magento_Theme/templates/html/header/ ── 🧊 EMPTY DIRECTORY (abandoned override).
        ├── layout/                  ◄── WHICH BLOCKS GO ON WHICH PAGE. §5.4.
        │   ├── default.xml ──────── EVERY page: header search override + 4 body-end blocks.
        │   ├── catalogsearch_result_index.xml ── /catalogsearch/result/  (search page)
        │   ├── fs_search_index.xml ──────────── /fs/search               (search page)
        │   ├── catalog_category_view.xml ────── category pages
        │   └── marketplace_seller_profile.xml ─ Webkul seller pages
        └── templates/
            ├── search/
            │   ├── results.phtml ── 1,217 lines. THE MAIN FILE. SSR + Shadow DOM +
            │   │                     the `ahySearchResults()` Alpine component. §7, §8, §9.2.
            │   ├── product-card-{desktop,mobile}.phtml ── Alpine card templates.
            │   ├── filters-{desktop,mobile}.phtml ─────── Facet UI.
            │   ├── zero-results.phtml ─────────────────── "No results" + popular products.
            │   ├── autocomplete.phtml ── 🧊 396 lines, DEAD (see §4.3).
            │   └── default.xml ───────── 🧊 A LAYOUT FILE IN THE TEMPLATES FOLDER. Never
            │                              loaded by Magento. This is why autocomplete is dead.
            ├── category/
            │   ├── results.phtml ── 828 lines. SSR (no Shadow DOM) + `ahyCategoryResults()`. §10.
            │   ├── product-card-{desktop,mobile}.phtml, filters-{desktop,mobile}.phtml,
            │   │   zero-results.phtml
            │   └── search-form.phtml ── 🧊 1,627 lines — THE LARGEST FILE IN THE MODULE,
            │                             AND IT IS COMPLETELY UNREFERENCED. §4.3.
            ├── html/header/
            │   ├── search-form.phtml ── 813 lines. The header box + the FULL-SCREEN
            │   │                         takeover overlay (`ahyModalSearch()`) +
            │   │                         `window.ahyTokenRefresh`. §9.1.
            │   └── product-card-{desktop,mobile}.phtml, filters-{desktop,mobile}.phtml,
            │       zero-results.phtml
            ├── modal/config-modal.phtml ── 641 lines. Configurable-product options modal,
            │                                declared once site-wide. §9.8.
            ├── slider/{smart-slider,collection}.phtml ── CMS-placed widgets. §11.
            ├── collection/results.phtml ─────────────── Collection landing page. §11.
            ├── global-style-vars.phtml ── Emits admin style config as CSS variables. §14.
            ├── geo-consent.phtml ──────── §13.
            └── visitor_cookie.phtml ───── §13.
```

### 4.3 🧊 Dead and orphaned code inventory

Verified by grepping the entire `dev2-app` tree for references. **This is important: roughly 2,100 lines of the module's ~18,500 live lines are unreachable.**

| File | Lines | Why it's dead | Evidence |
|---|---|---|---|
| `view/frontend/templates/category/search-form.phtml` | 1,627 | Not referenced by any layout XML, block, or template anywhere in `dev2-app`. Appears to be an early copy of the header search form. | `grep -rn "category/search-form" dev2-app` → no hits |
| `view/frontend/templates/search/autocomplete.phtml` | 396 | Only referenced by `templates/search/default.xml`, which is itself never loaded | see next row |
| `view/frontend/templates/search/default.xml` | 22 | **A layout XML file in the `templates/` directory.** Magento only loads layout XML from `view/<area>/layout/`. This file is inert. It also references `search/search-form.phtml`, which does not exist. | File location; `default.xml:5` points at a missing template |
| `Controller/Suggest/Index.php` | 66 | No caller. Nothing links to `/smartsearch/suggest`. Also disables TLS verification. | `grep -rn "smartsearch/suggest"` → no hits |
| `Block/Adminhtml/System/Config/SliderInfo.php` | 71 | Not referenced by `etc/adminhtml/system.xml` (the `sliders` config group it belonged to was removed). Ironically it contains the only in-product documentation of how to place a slider. | `grep -c SliderInfo etc/adminhtml/system.xml` → 0 |
| `view/frontend/Magento_Theme/templates/html/header/` | — | Empty directory. A leftover from the abandoned template-override attempt that `Block/HeaderSearchForm.php`'s docblock describes. | `ls` → empty |
| `Controller/Search/Index.php` — `LOG_FILE` const, `$helper`, `$storeManager`, `$logger` | 4 | Injected and declared, never used. `execute()` only sets a page title. | `Controller/Search/Index.php:19-38` |
| 214 × `*.bak*` files | ~thousands | Manual snapshots | `find . -name "*.bak*" \| wc -l` |

⚠️ The `.bak` files are not merely untidy. The theme's Tailwind build scans `app/code/**/*.phtml` (`design/frontend/Ahy/Everest2/web/tailwind/tailwind.config.js:245`), which means **Tailwind classes that only exist in abandoned backup templates are still compiled into production CSS**, and dead templates keep alive utility classes nobody uses.

---

## 5. Configuration and wiring — how Magento is told about this module

### 5.1 Module identity and load order

`registration.php` registers the name. `etc/module.xml` sets version and load order:

```xml
<module name="FalcoSense_Search" setup_version="1.0.5">
    <sequence>
        <module name="Magento_MessageQueue"/>
        <module name="Magento_CatalogInventory"/>
    </sequence>
</module>
```

**WHAT** `sequence` means: "load these modules *before* me". It affects the order in which config files merge and observers fire.

⚠️ **WHY this is incomplete:** the module also depends on `Magento_CatalogSearch` (it overrides its route and its controller), `Magento_PageCache` (it relies on `cacheable="false"`), and `Ahy_ThemeCustomization` (two AJAX endpoints). None are declared. The sibling module `Ahy_SmartSearchLuma` declares a fuller list (`Magento_Catalog`, `Magento_CatalogSearch`, `Magento_PageCache`) — worth copying.

### 5.2 `etc/di.xml` — the three `preference` overrides you must know about

A `preference` is a global class substitution. These three are the highest-blast-radius lines in the module.

**1. Magento's search controller is replaced entirely.**

```xml
<preference for="Magento\CatalogSearch\Controller\Result\Index"
            type="FalcoSense\Search\Controller\Result\Index"/>
```

> *"Bypass Magento's CatalogSearch result controller — it runs a full product collection query (150k+ rows) even though we render results via our own API. Our replacement just renders the page layout with no catalog queries."* — `etc/di.xml:19`

**WHY:** Magento's controller eagerly loads a search result collection before layout renders. Even with the result block removed from layout, the query still runs. On a 150k-product catalogue that is pure waste. **Consequence:** any other extension that plugins/extends `Magento\CatalogSearch\Controller\Result\Index` may silently stop working.

**2. The SSR data provider is bound.**

```xml
<preference for="FalcoSense\Search\Api\PlpDataProviderInterface"
            type="FalcoSense\Search\Service\Plp\FalcoSensePlpProvider"/>
```

**WHY:** This is the seam that makes SSR swappable. Point it at a caching decorator or a different backend and nothing else changes. (`Ahy_SmartSearchLuma` does exactly that — it has both a `CachedPlpProvider` and an `OpenSearchPlpProvider`.) See §7.2 and §20.

**3. Cart images come from the module.**

```xml
<preference for="Magento\Checkout\Model\Cart\ImageProvider"
            type="FalcoSense\Search\Model\Cart\ImageProvider"/>
```

**WHY:** so mini-cart/cart thumbnails use the same FalcoSense-compressed image paths as the product grids. See §15.

**The other half of `di.xml`: three dedicated loggers.** Magento's `virtualType` lets you configure a class differently under a new name without writing a subclass:

```xml
<virtualType name="FalcoSense\Search\Logger\RealtimeSyncLoggerVirtual"
             type="FalcoSense\Search\Logger\RealtimeSyncLogger"> … </virtualType>
```

…and then those named loggers are injected into specific classes (`ProductSaveObserver`, `StockChangeObserver`, `ProductDeleteObserver`, `ProductSyncService`, `FullSyncCommand`, `ProductImageCompressionService`). The payoff is §16's debugging playbook: each subsystem writes to its own log file instead of drowning in `var/log/system.log`.

### 5.3 `etc/frontend/routes.xml` — three routes, one of them a hijack

```xml
<route id="smartsearch" frontName="smartsearch">        <!-- module's own AJAX endpoints -->
<route id="catalogsearch" frontName="catalogsearch">    <!-- OVERRIDE, before="Magento_CatalogSearch" -->
<route id="fs" frontName="fs">                          <!-- short pretty URL for the results page -->
```

**WHY there are two search URLs:**

> *"Dedicated short 'fs/search' URL for the header search form's results page, so the visible address bar reads /fs/search?q=... instead of /catalogsearch/result/?q=...."* — `etc/frontend/routes.xml:21`

**Which one actually gets used?** The header form's `action` is `/fs/search` (`html/header/search-form.phtml:29,91`), so typing and pressing Enter lands on **`/fs/search`**. But `/catalogsearch/result/` is still live and still reachable — the header overlay's "search anyway" link (`html/header/search-form.phtml:340`) and the zero-results "popular searches" links (`search/zero-results.phtml:60`) both navigate to `/catalogsearch/result/?q=`.

⚠️ **A stale comment to distrust:** `view/frontend/layout/catalogsearch_result_index.xml:14` claims *"The header search form submits here (native /catalogsearch/result/…), so this is the actual page most searches land on"*. That is no longer true — the form points at `/fs/search`. Both pages render the identical block/template set, so the outcome is the same either way, but do not trust that comment when reasoning about traffic.

**Important consequence for SSR:** because both routes exist, `Model/Plp/PageContext.php:35` must whitelist both full action names:

```php
private const ACTIONS_SEARCH = ['catalogsearch_result_index', 'fs_search_index'];
```

> *"Both of this module's search routes resolve to the full action names checked below without the dispatch-name pitfall Ahy_SmartSearchLuma hit earlier (there, a route id that didn't match its frontName produced the wrong full action name and silently broke this exact kind of check)."* — `Model/Plp/PageContext.php:27`

### 5.4 Layout XML, page by page

| Layout file | Page it targets | What it does |
|---|---|---|
| `default.xml` | **Every storefront page** | (a) Re-points the theme's `header-search` block at `Block\HeaderSearchForm` + this module's template, with 5 child blocks (filters ×2, zero-results, cards ×2). (b) Adds 4 blocks to `before.body.end`: `global-style-vars`, `visitor_cookie`, `geo-consent` (gated by `ifconfig="smart_search/general/enabled"`), and the site-wide `config-modal`. |
| `catalogsearch_result_index.xml` | `/catalogsearch/result/` | Adds `Block\Search` + `search/results.phtml` with 5 children, **all `cacheable="false"`**. Removes `search.result` and `catalogsearch.leftnav`. |
| `fs_search_index.xml` | `/fs/search` | Same block tree. Additionally forces `layout="1column"` and removes 7 more blocks/containers (`klevu_content_top`, `catalog.compare.sidebar`, `sidebar.main`, `mpassign.list`, `search_result_list`, `search.search_terms_log`). |
| `catalog_category_view.xml` | Category pages | Adds `Block\Category` + `category/results.phtml` with 5 children, all `cacheable="false"`. Removes `category.products`, `search.result`, and the `sidebar.main` container. |
| `marketplace_seller_profile.xml` | Webkul seller profile | Injects `ViewModel\StyleConfig` as an argument so seller-page grids match the configured card style. |

**Two removals worth understanding, because they are performance fixes, not cosmetics:**

> *"Remove Magento_LayeredNavigation's search sidebar block. It is declared independently of search.result…: `Magento\LayeredNavigation\Block\Navigation\Search` builds its facet/filter counts from the configured search engine (Elasticsearch/OpenSearch) **eagerly during layout generation, on every request**, regardless of which controller ran or whether it's ever echoed."* — `catalogsearch_result_index.xml:33`

> *"Webkul MpAssignProduct injects a full product ListProduct block — remove it"* — `fs_search_index.xml:57`

**Lesson for a new developer:** in Magento, *removing a block from layout is not enough to stop it doing work*, because some blocks do their work during layout generation. You must `remove="true"` them, and sometimes replace their controller too (§5.2).

### 5.5 ⚠️ The configuration matrix — read this before changing any setting

The module reads **24** config paths. Only **16** have defaults in `etc/config.xml`. Only about **6 functional settings** are visible in the admin UI. The rest are invisible and can only be changed with `bin/magento config:set` or a direct DB write.

| Config path | Default in `config.xml` | Visible in admin? | What it does | Read at |
|---|---|---|---|---|
| `general/frontend_enabled` | `1` | ✅ Yes | Master switch for the custom storefront UI | `Helper/Data.php:70` |
| `general/enabled` | `1` | ✅ Yes | Master switch for sync (also gates the geo-consent block) | `Helper/Data.php:75` |
| `general/realtime_sync_enabled` | **none** ⚠️ | ✅ Yes | Sync on product save / stock change | `Helper/Data.php:80` |
| `general/endpoint_url` | `''` | ✅ Yes | **The base URL of the platform.** Everything derives from this. | `Helper/Data.php:85` |
| `general/api_key` | `''` | ✅ Yes (password) | Secret `X-Api-Key`. Never sent to the browser. | `Helper/Data.php:130` |
| `general/search_url` | `''` | ✅ Yes | Legacy search URL; used only by `zero-results.phtml` | `Helper/Data.php:90` |
| `general/products_per_page` | `12` | ❌ **No** | Page size for SSR *and* the platform request | `Helper/Data.php:95` |
| `general/platform_store_id` | `1` | ❌ **No** | Fallback only — see the ⚠️ below | `Helper/Data.php:135` |
| `plp/platform_timeout_ms` | **none** ⚠️ | ❌ **No** | **The SSR budget. Falls back to 500 ms in code.** | `Helper/Data.php:101` |
| `no_results_modal/enabled` | `1` | ❌ No | Zero-results modal | `Helper/Data.php:209` |
| `no_results_modal/title` | `No results for "{query}"` | ❌ No | | `Helper/Data.php:214` |
| `no_results_modal/subtitle` | `Try a new search…` | ❌ No | | `Helper/Data.php:219` |
| `no_results_modal/section_heading` | `Trending Products` | ❌ No | | `Helper/Data.php:224` |
| `no_results_modal/product_count` | `6` (clamped 1–12) | ❌ No | | `Helper/Data.php:229` |
| `sliders/slider_1_slug` … `_4_slug` | **none** ⚠️ | ❌ **No** (the help block is orphaned) | Which platform slider feeds each widget | `Helper/Data.php:240` |
| `webhook/secret` | **none** ⚠️ | ❌ **No** | Inbound webhook verification | `Helper/Data.php:155` |
| `cron/last_sync_at` | `''` | ❌ No (internal) | Delta cursor for cron sync | `Helper/Data.php:160` |
| `cron/full_sync_requested` | **none** | ❌ No (internal) | Admin-button → cron handshake flag | `Helper/Data.php:171` |
| `image_compress/enabled` | `1` | ❌ No | | `Helper/Data.php:186` |
| `image_compress/last_run_at` | `''` | ❌ No (internal) | Delta cursor | `Helper/Data.php:192` |
| `image_compress/batch_size` | `200` | ❌ No | | `Helper/Data.php:203` |

Style/appearance config (`add_to_cart_button/*`, `product_card/*`) is admin-visible and covered in §14.

**Three specific traps in this table:**

1. **`realtime_sync_enabled` has no default**, and `isSetFlag()` on a missing value returns `false`. So **real-time sync is OFF on a fresh install** even though the admin field exists and `enabled` defaults to on. Only the cron sync runs until someone explicitly turns it on.
2. **`plp/platform_timeout_ms` has no default and no admin field.** The 500 ms SSR budget lives as a magic fallback in `Helper/Data.php:104`. This is the single most performance-sensitive number in the module (§7.7) and it is effectively hard-coded.
3. ⚠️ **`getPlatformStoreId()` is not what it looks like.** `Helper/Data.php:135-153` ignores the config value unless everything else fails; it computes a *position* in the sorted list of store IDs:

   ```php
   $position = array_search((int) $storeId, $storeIds);
   if ($position !== false) { return (int) $position + 1; }
   ```

   > *"Dynamic calculation matching FourSeasons approach"* — `Helper/Data.php:137`

   On a single-store site this **always returns `1`**, regardless of the configured value. The frontend code knows this and deliberately routes around it:

   > *"platform_store_id intentionally omitted — getPlatformStoreId()'s position-based calculation is wrong for single-store-view sites (always resolves to 1). Omitting it lets the backend fall back to the store already correctly configured on the API key itself."* — `search/results.phtml:710`

   So the parameter is *computed*, then *passed around everywhere* (into `getSearchToken()`, into templates), and then *deliberately not sent* to the API. It is vestigial but load-bearing in confusing ways. Do not "fix" it without tracing every call site. Note in particular `Controller/Search/Token.php:38-39`, which passes the *platform* store ID into `getToken()` where every other caller passes a *Magento* store ID — a latent inconsistency in the token cache key.

---

## 6. Rendering — how a page actually gets drawn

### 6.1 The Magento render pipeline, for a fresher

When a shopper requests `/fs/search?q=jacket`, this happens in order:

```
1.  index.php boots Magento (autoloader, DI container, config cache, DB connection)
2.  Router matches the URL:  route "fs" + controller "search" + action "index"
        → full action name: "fs_search_index"
        → class: FalcoSense\Search\Controller\Search\Index
3.  Controller::execute() runs.  Here it only sets the page <title> and returns a Page result.
4.  LAYOUT GENERATION. Magento merges every layout XML file whose name matches this page:
        default.xml  +  fs_search_index.xml  (+ every theme's and every other module's copies)
        The result is a tree of Block objects, each with a template assigned.
5.  RENDERING. Magento walks the block tree and calls toHtml() on each block, which
    executes its .phtml template with $block bound to the block instance.
        ↳ It is DURING THIS STEP that Block\Search::getPlpResult() fires the SSR HTTP
          call to FalcoSense.  (§7)
6.  The assembled HTML string is sent to the browser.
7.  IN THE BROWSER: Alpine.js boots, finds x-data attributes, and takes over. (§6.4)
```

**The mental model that matters:** steps 4–6 are *server-side*; step 7 is *client-side*. This module does meaningful work in both, and the hardest bugs in it live at the seam between step 6 and step 7.

### 6.2 The four rendering surfaces

The module draws product grids in four visually similar but structurally different places. They do **not** share code.

| # | Surface | Entry template | Alpine component | Renders server-side? | Shadow DOM? |
|---|---|---|---|---|---|
| 1 | **Header live-search takeover** | `html/header/search-form.phtml` | `ahyModalSearch()` | ❌ No — pure client-side | ❌ No |
| 2 | **Search results page** (`/fs/search`, `/catalogsearch/result/`) | `search/results.phtml` | `ahySearchResults()` | ✅ **Yes** (canonical view only) | ✅ **Yes** |
| 3 | **Category page** | `category/results.phtml` | `ahyCategoryResults()` | ✅ **Yes** (every page & sort) | ❌ No |
| 4 | **Sliders / collections** (CMS-placed) | `slider/smart-slider.phtml`, `collection/results.phtml` | (see §11) | (see §11) | ❌ No |

### 6.3 ⚠️ The product card exists in (at least) seven places

This is the module's biggest maintainability problem, and it is worth stating precisely because it is the root cause of most visual-inconsistency bugs.

| Copy | File | Language |
|---|---|---|
| 1 | `search/product-card-desktop.phtml` | Alpine/HTML |
| 2 | `search/product-card-mobile.phtml` | Alpine/HTML |
| 3 | `category/product-card-desktop.phtml` | Alpine/HTML |
| 4 | `category/product-card-mobile.phtml` | Alpine/HTML |
| 5 | `html/header/product-card-desktop.phtml` + `-mobile` | Alpine/HTML |
| 6 | `search/results.phtml:24-60` — the `$renderSsrCard` closure | **PHP string concatenation** |
| 7 | `category/results.phtml:17-53` — a *second* `$renderSsrCard` closure | **PHP string concatenation** |
| (+) | `slider/smart-slider.phtml`, `collection/results.phtml` | Alpine/HTML |
| (+) | `Block/Adminhtml/System/Config/CardPreview.php` | PHP (admin preview) |

`diff`ing them shows they are near-identical with small deliberate differences (the header copy adds a "N Left" stock badge; the category copy uses `px-6` where search uses `px-4`; only the search copy calls `trackClick()`).

**The concrete consequence:** copies 6 and 7 (the PHP SSR cards) must produce visually identical HTML to copies 1–4 (the Alpine cards), because on first paint the shopper sees the PHP card and ~1 second later Alpine replaces it with its own. Any drift between them shows up as a visible flicker or layout jump. Two already-known drifts:

- **Image URL host mismatch.** `category/results.phtml:656` builds image URLs against a hard-coded CDN — `const falcosenseBase = 'https://static.everest.com/media/falcosense/800x800'` — while the PHP SSR renderer for the same page builds *relative* URLs, `/media/falcosense/800x800/…` (`Service/Plp/FalcoSensePlpProvider.php:314`). So on the category page the SSR card and the hydrated card request **different URLs for the same image**, defeating the browser cache and guaranteeing a re-fetch on hydration. Search's client code (`search/results.phtml:1001`) uses relative URLs and matches PHP correctly.
- **Grid class mismatch.** `category/results.phtml:83` puts `ahy-product-grid` on the SSR grid; `search/results.phtml:199` does not.

The provider's own docblock is admirably honest that this duplication is deliberate:

> *"Same resolution algorithm as search/results.phtml's client-side imgUrl() (lines 741-757)… Kept in sync deliberately, not shared, since a shared PHP/JS helper isn't practical here — this is the SSR-side copy."* — `Service/Plp/FalcoSensePlpProvider.php:274`

### 6.4 The Alpine.js hydration model

**WHAT Alpine.js is:** a small JavaScript library that adds reactivity to plain HTML via attributes. It is the standard for Hyvä themes because it needs no build step. Key attributes used here:

| Attribute | Meaning |
|---|---|
| `x-data="fn()"` | Declares a component; `fn()` returns its state object |
| `x-init="init()"` | Run this once when the component boots |
| `x-for` (on a `<template>`) | Loop — this is how a single card template becomes 18 cards |
| `x-show` / `x-text` / `x-html` / `:attr` | Reactive display, text, HTML, attribute binding |
| `x-cloak` | Hide until Alpine has booted (prevents a flash of raw template) |
| `x-effect` | Re-run whenever any state it reads changes |
| `x-teleport="body"` | **Move this DOM node to `document.body`** — matters a lot in §8.7 |

**The pattern used throughout this module:** the `.phtml` renders *one* card as an inert `<template x-for=…>`, and Alpine clones it per product:

```html
<div x-show="!loading && results.length > 0" class="hidden md:grid grid-cols-2 xl:grid-cols-3 ahy-product-grid">
    <template x-for="(product, index) in sortedResults()" :key="…">
        <?= $block->getChildHtml('product_card_desktop') ?>
    </template>
</div>
```
— `search/results.phtml:512-516`

**A recurring workaround you will see three times** — `alpine:initialized` listeners like `search/results.phtml:1110` and `category/results.phtml:729`:

```js
document.addEventListener('alpine:initialized', function() {
    var el = document.querySelector('[x-data^="ahyCategoryResults"]');
    if (!el || !el.hasAttribute('x-cloak')) return;
    if (el._x_dataStack) { el.removeAttribute('x-cloak'); }
    else if (window._ssAlpine) { window._ssAlpine.initTree(el); }
});
```

**WHY:** the component sometimes isn't initialised by Alpine's own bootstrap (timing, or DOM position). This checks whether Alpine actually attached (`_x_dataStack`) and, if not, initialises the subtree manually. It is a symptom of components living in unusual DOM positions (teleported, re-parented, or inside a shadow root) rather than a designed feature.

### 6.5 Mobile vs desktop: two grids, always both rendered

Every surface renders **two complete grids** and hides one with CSS:

```html
<div x-show="…" class="hidden md:grid …">      <!-- desktop: hidden below 768px -->
<div x-show="…" class="md:hidden flex flex-col …"> <!-- mobile: hidden at/above 768px -->
```

**WHY:** the mobile card has a genuinely different layout (horizontal, edge-to-edge via the `.ahy-mobile-card` transform trick), not just different spacing. **COST:** every product is in the DOM twice, and every card change must be made in two files. This is a deliberate, accepted trade-off, but it doubles the maintenance surface described in §6.3.

---

## 7. SSR (Server-Side Rendering) — what, why, how

### 7.1 WHAT "SSR" means here — and what it does not mean

**Plain English:** Before this work, when you opened a search or category page, the HTML that arrived from the server contained **no products at all** — just an empty shell. Your browser then had to make a *second* request, to FalcoSense, to find out what products to show. Only then did anything appear.

SSR means: **Magento asks FalcoSense for the products itself, on the server, and puts the real product HTML into the page before sending it.** The shopper sees products in the first response. The browser does not need to make that second request.

**What it is NOT:**

- ❌ It is **not** a JavaScript framework rendering on the server (no Node, no React SSR, no hydration framework).
- ❌ It does **not** replace the client-side code. The Alpine.js components are entirely intact and still handle every filter, sort and page change.
- ❌ It does **not** make FalcoSense respond faster. It removes a *network round trip from the shopper's device*, which is a different (and real) win — see §7.8.

**The precise mechanism, in one sentence:** PHP makes the same API call the JavaScript would have made, renders the results as plain HTML, and *also* embeds the raw API response as JSON in the page so the JavaScript can adopt it instead of re-fetching.

### 7.2 The architecture: a Port and an Adapter

The SSR data layer is deliberately built as a hexagonal (ports-and-adapters) seam. This is the cleanest code in the module.

```
        Block\Search / Block\Category          ← callers, know nothing about HTTP
                    │
                    │  fetch(PlpQuery): PlpResult
                    ▼
      ┌─────────────────────────────────┐
      │  Api\PlpDataProviderInterface   │      ← THE PORT (the contract)
      └─────────────────────────────────┘
                    ▲
                    │  bound by etc/di.xml <preference>
                    │
      ┌─────────────────────────────────┐
      │  Service\Plp\                   │      ← THE ADAPTER
      │  FalcoSensePlpProvider          │
      └────────────┬────────────────────┘
                   │ uses
                   ├──► SearchTokenService      (auth)
                   ├──► Helper\Data             (URL + timeout config)
                   └──► Service\Plp\PlatformHttpClient  (the only ms-timeout HTTP client)
                                │ throws
                                └──► PlatformRequestException  (never escapes the adapter)
```

**The contract is the important part.** `Api/PlpDataProviderInterface.php:14`:

> *"Implementations MUST NOT throw for an unreachable/slow/empty platform — return `PlpResult::unavailable()` instead, so the caller-side code path is always just 'did I get something usable back.'"*

This single rule is why a FalcoSense outage cannot produce a 500 error page. Every failure path in `FalcoSensePlpProvider` returns `PlpResult::unavailable()`: endpoint not configured (`:65`), no token (`:71`), HTTP failure (`:101`), wrong query type (`:60`).

**`PlatformHttpClient` exists for one reason — millisecond timeouts:**

> *"Millisecond timeouts (CURLOPT_*_MS) are why this doesn't use `Magento\Framework\HTTP\Client\Curl` — that wrapper only exposes whole-second timeouts, and 'half a second, then give up' is the entire point here."* — `Service/Plp/PlatformHttpClient.php:16`

It sets `CURLOPT_TIMEOUT_MS` to the budget, `CURLOPT_CONNECTTIMEOUT_MS` to `min(budget, 400)`, and `CURLOPT_NOSIGNAL => true` (required for ms timeouts to work reliably in PHP). It treats **five** things as failure: transport error, non-2xx, non-JSON body, `success: false` in the payload, and — as a warning only — a response slower than 80% of the budget (`:91`).

⚠️ Note: this is the *fourth* HTTP call site in the module. `SearchTokenService`, `Controller\Suggest\Index`, `ProductSyncService` and `Block\Slider\Products` each roll their own cURL with whole-second timeouts. Only this one has proper error differentiation. The docblock admits it: *"the rest of the module's existing HTTP call sites use whole-second CURLOPT_TIMEOUT with no error differentiation; this is the one exception, deliberately."*

### 7.3 The value objects

All four are `final` and fully immutable (PHP 8 `readonly` promoted properties). None knows anything about Magento or about HTTP.

| Class | Role | Notable member |
|---|---|---|
| `PlpQuery` | The request. `contextType` (`search`/`category`), `storeId`, `platformStoreId`, `page`, `perPage`, `sort`, `filters`, `priceMin/Max`, `categoryId`, `categoryName`, `searchQuery` | `cacheKey()` — a SHA-256 of every field, with filters normalised and sorted. **Currently unused** — built for a caching layer that doesn't exist yet (§20). |
| `PlpResult` | The response. `items[]`, `facets[]`, `total`, `page`, `perPage`, `source`, `fetchedAt`, `meta` | `isUsable()` — see below |
| `PlpItem` | One product | `toArray()` keys match the API's wire format *exactly* |
| `PlpFacet` | One filter group | `min`/`max` only emitted when non-null (for the price facet) |

**`PlpResult::isUsable()` encodes a real product decision** (`Model/Plp/PlpResult.php:50`):

> *"Usable = we have real products to show. An empty-but-successful platform response (a genuinely empty search) is NOT usable for SSR — better to let Alpine's existing zero-results UI render than publish an empty crawlable grid."*

So a zero-result search deliberately falls through to client-side rendering. That is intentional: you do not want Google indexing an empty grid.

**Why `PlpItem::toArray()` matching the wire format matters so much** (`Model/Plp/PlpItem.php:6`):

> *"Field names and toArray() shape intentionally match exactly what search/results.phtml's Alpine component already expects from a live /api/v1/products response (product_id, sku, name, url_key, image, price, special_price, brand, type, variants, in_stock) — the embedded SSR payload and a live fetch() response are the same shape, so Alpine needs zero new mapping logic to consume either one."*

This is the design decision that made SSR cheap to add. Because the SSR payload is byte-compatible with a live API response, the JavaScript required **zero** new mapping code — it just reads from a different source.

### 7.4 `PageContext` — the gate that decides whether SSR happens

`Model/Plp/PageContext.php` answers "is this a page we SSR, and what exactly is being asked for?" It has **two different policies**, and knowing which is which is essential.

**Search: canonical view only.**

```php
public function buildSearchQuery(): ?PlpQuery
{
    if (!$this->isSearchPage()) return null;                    // wrong page
    $q = trim((string) $this->request->getParam('q', ''));
    if ($q === '') return null;                                 // blank query
    if (!$this->isCanonicalRequest()) return null;              // filtered/sorted/paged
    …
}
```
— `Model/Plp/PageContext.php:57`

`isCanonicalRequest()` (`:96`) returns false if `p` is set to anything but `1`, or if **any** of `brand`, `price_min`, `price_max`, `sort`, `bypass_spell` is present.

**Category: unconditional, every page and sort.**

```php
public function buildCategoryQuery(): ?PlpQuery
{
    if (!$this->isCategoryPage()) return null;
    $category = $this->layerResolver->get()->getCurrentCategory();
    if (!$category || !$category->getId()) return null;
    $page = max(1, (int) $this->request->getParam('p', 1));
    $sort = trim((string) $this->request->getParam('sort', ''));
    …  // no canonical gate at all
}
```
— `Model/Plp/PageContext.php:124`

**WHY the asymmetry?** Two reasons, both documented in the code:

1. **A product decision.** The category page is the SEO-critical surface, so it renders server-side always. (The prior session's handoff records the instruction verbatim: *"the category page is main… every fucking time should come from the ssr"*.)
2. **A technical one that makes it easy.** `Model/Plp/PageContext.php:21`:
   > *"category/results.phtml's own Alpine `init()` only ever reads `p`/`sort` from the URL to begin with (filters there are pure post-load client state, never URL-encoded), so there's no 'filtered view' for SSR to skip the way there is for search."*

   In other words: on the category page, filters are never in the URL, so there is no filtered URL for SSR to fail to cover. SSR covers 100% of what a category URL can express. On the search page, filters *are* in the URL, so SSR covers only the unfiltered first page.

⚠️ **Consequence to be aware of:** category filter selections are not shareable or bookmarkable — reload the URL and the filters are gone. That is pre-existing behaviour, not something SSR introduced, but SSR's design now depends on it. **If anyone ever adds URL-encoded filters to the category page, `buildCategoryQuery()` must grow a canonical gate or SSR will render the wrong products.** This is the most important latent landmine in the SSR design.

### 7.5 The handoff: SSR grid → JSON payload → Alpine seed

This is the actual mechanism. Three pieces, in `search/results.phtml`:

**Piece 1 — the server-rendered grid** (`:196-205`). Plain HTML, no Alpine:

```php
<div id="fs-ssr-grid" class="flex px-4 md:pr-0 lg:pl-6 pt-0 pb-12 ahy-filter-layout">
    <aside class="hidden md:block ahy-filter-sidebar" aria-hidden="true"></aside>
    <div class="flex-1 min-w-0">
        <div class="grid grid-cols-2 xl:grid-cols-3" style="gap:var(--ahy-grid-gap);">
            <?php foreach ($plpResult->items as $item): ?>
                <?= $renderSsrCard($item) ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
```

**Piece 2 — the embedded payload** (`:206`). An inert JSON island:

```php
<script type="application/json" id="fs-ssr-payload"><?=
    json_encode($plpResult->toArray(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
?></script>
```

`type="application/json"` means the browser never executes it. The four `JSON_HEX_*` flags escape `<`, `&`, `'`, `"` so a product name containing `</script>` cannot break out — this is the correct XSS defence for a JSON island.

**Piece 3 — the Alpine seed** (`:632-697`, inside `init()`):

```js
const shadowRoot = this.$root.getRootNode();
shadowRoot.getElementById('fs-ssr-grid')?.remove();     // (a) discard the static grid

const isCanonical = this.page === 1 && !sortParam && !brandParam && !this.bypassSpell;
const payloadEl = isCanonical ? shadowRoot.getElementById('fs-ssr-payload') : null;
if (payloadEl) {
    const seed = JSON.parse(payloadEl.textContent);
    if (seed && seed.success) {
        this.results = seed.data || [];                 // (b) adopt the server's data
        this.total = seed.pagination?.total || 0;
        this.pageSize = seed.pagination?.per_page || this.pageSize;
        this.totalPages = Math.max(1, Math.ceil(this.total / this.pageSize));
        this.apiFacets = seed.facets || [];
        this.buildFilters();
        this.loading = false;
        return;                                          // (c) SKIP the fetch entirely
    }
}
// …otherwise fall through to the original fetch() flow, unchanged
```

**The three-step choreography:**

| Step | What happens | Why |
|---|---|---|
| (a) | The static `#fs-ssr-grid` is **removed** | It was only ever a pre-JS placeholder. Keeping it would mean two grids on screen and two sources of truth. |
| (b) | Alpine's state is seeded from the JSON | Because `PlpItem::toArray()` matches the API shape, this is a straight assignment |
| (c) | `return` — no `fetch()` | This is the actual performance win: one fewer network request from the shopper's device |

**⚠️ The JS canonical check must stay in lockstep with the PHP one.** The JS check (`search/results.phtml:667`) and `PageContext::isCanonicalRequest()` (`:96`) are two independent implementations of the same rule. They have already drifted once — a prior fix (commit `49b14a9`) added `bypass_spell` to the PHP side after the JS side already had it. If they disagree, you get either a wasted double-fetch (JS re-fetches what PHP already fetched) or, worse, Alpine seeding page 1's data onto page 3. **Any change to one must be mirrored in the other.** This is the #1 thing to protect in this design.

**Graceful degradation is total.** If `$plpResult` is `null`, pieces 1 and 2 simply don't render (`search/results.phtml:180` — `if ($plpResult !== null)`), `payloadEl` is `null`, and the JS falls through to the pre-existing `fetch()` path. The page behaves exactly as it did before SSR existed. There is no separate "SSR off" code path to maintain.

### 7.6 ⚠️ Why every SSR block is `cacheable="false"` — and why that is mandatory

Every block in the three SSR layout files carries `cacheable="false"`. This is not precautionary.

**WHAT Full Page Cache (FPC) does:** Magento (or Varnish in front of it) stores the complete HTML response for a URL and serves it to subsequent visitors without running PHP at all. It is the single biggest performance feature in Magento.

**WHY it is catastrophic here:** FPC keys on the URL. Before SSR, the search page's HTML was identical for every query (an empty shell) and the products came from the browser's own API call — so caching it was harmless. **Once the block renders real per-query content, the cached copy is a specific query's results.** The failure mode:

> *"whichever response happened to be cached first for a given query (potentially from before SSR existed, or from a moment the platform was briefly slow) gets served to every subsequent visitor searching that same term, indefinitely, regardless of how correct the current render logic is."* — `catalogsearch_result_index.xml:8`

This was a **real, observed bug**, not a theoretical one: `fs_search_index.xml` already had `cacheable="false"`, `catalogsearch_result_index.xml` did not, and stale CSR responses were being served on the native search route. Fixed in commit `b85892d`.

**How `cacheable="false"` works:** a single non-cacheable block makes Magento mark the *entire page response* as non-cacheable (`Cache-Control: no-store`). It is page-level, not block-level.

**⚠️ The cost, stated plainly:** the search page and **every category page** now bypass Full Page Cache completely. Every category page view runs a full Magento bootstrap plus a live FalcoSense call. Measurements (§7.8) put Magento's own bootstrap at **~700 ms–3 s on dev2**, which is ~89% of the response time. **SSR bought a saved round-trip and paid for it by giving up FPC.** Whether that is net-positive depends on infrastructure, and it is the biggest open architectural question in the module (§20).

The sibling module `Ahy_SmartSearchLuma` solves exactly this with a custom cache type (`Model/Cache/Type/Plp.php`, `Service/Plp/CachedPlpProvider.php`, `Model/Plp/CacheInvalidator.php`, `Cron/PlpCacheWarmer.php`) — caching the *PLP data* rather than the *page*. `PlpQuery::cacheKey()` already exists in this module for precisely that purpose and is currently unused. That is the ready-made path forward.

### 7.7 Failure modes and what the shopper sees

| Failure | Detected at | Shopper sees | Log line |
|---|---|---|---|
| `endpoint_url` not configured | `FalcoSensePlpProvider.php:64` | Normal page, CSR fallback | `[SmartSearch][PLP] Platform endpoint not configured` |
| Token fetch failed | `:69` (empty token) | Normal page, CSR fallback (which will also fail → empty grid) | `[SmartSearch][PLP] No search token available` |
| Platform slower than budget (500 ms) | `PlatformHttpClient.php:66` | Normal page, CSR fallback | `[SmartSearch][PLP] PLP request transport error (28) after 501ms` |
| Platform returns non-2xx | `:72` | CSR fallback | `PLP request HTTP 503 after 88ms` |
| Platform returns `success:false` | `:85` | CSR fallback | `PLP request returned success=false: …` |
| Response 400–500 ms (near budget) | `:91` | Works, but fragile | ⚠️ `Slow platform response: 430ms (budget 500ms)` — **watch for this in logs** |
| Genuinely zero results | `PlpResult::isUsable()` | Alpine's zero-results UI | — |
| Malformed embedded JSON | `search/results.phtml:682` | CSR fallback | console: `SSR payload parse error, falling back to fetch()` |

**The design guarantee:** every SSR failure degrades to the pre-SSR client-side behaviour. SSR cannot take the site down. What it *can* do is add up to 500 ms of latency to a page that then renders client-side anyway — the worst case is "slow *and* no benefit", never "broken".

### 7.8 Measured performance (from the September 2026 benchmarking session)

Two independent methods were used and cross-checked. Full methodology and raw data are preserved in `FALCOSENSE-SEARCH-SSR-HANDOFF.md` §4; this is the summary.

**Method 1 — in-process timing.** `[SmartSearch][BENCH]` log lines wrapping only the platform call and the response mapping (`FalcoSensePlpProvider.php:79-110`, `:150-177`). Read with `grep BENCH var/log/system.log`.

**Method 2 — browser DevTools** document-request TTFB, which is Magento's bootstrap *plus* the FalcoSense call.

**Category page results** — 18 samples, one user, 6 categories, 15-minute window on 2026-09-02:

| Metric | Value |
|---|---|
| Platform round-trip | **63–132 ms**, mean ≈ 85 ms |
| Mapping overhead | ≤ a few ms (negligible) |
| Full document TTFB (`hunting-gear.html`) | **791.96 ms** |
| **FalcoSense's share of the response** | **≈ 11%** |
| **Magento's own bootstrap/render share** | **≈ 89%** |

The 2–3 second spikes observed on some category clicks **never appeared in the BENCH numbers** — those are entirely Magento-side.

**Production comparison** (old, CSR-only, from the user's browser): first search for "tshirt" showed 460 ms waiting on the `products` XHR; a second showed 272 ms (the drop is TCP connection reuse, not caching). So dev2's ~800 ms–1.7 s baseline is a **dev2 infrastructure issue**, not an architectural one.

**Two methodology mistakes were made and corrected during that session; both are worth remembering:**

1. Testing with nonsense query strings (to force a cache miss) triggered the platform's slow "no results" path (1.3–1.7 s), making CSR look far worse than it was. Fixed by using real search terms.
2. Comparing SSR's *whole-page* TTFB against CSR's *fetch-only* time — measuring different things on each side. Fixed by isolating both to the platform round-trip, which showed them roughly equivalent in raw speed. **The real SSR benefit is eliminating the round trip's network distance from the shopper's device, not making the platform respond faster.**

**Honest characterisation of this data, as given at the time:** *"In-process server-side timing instrumentation of the production code path, sampled under light/single-user load — not a load test."* It says nothing about behaviour under concurrent traffic, and because of the 500 ms timeout it structurally cannot show worst-case stalls — only how it performs when it succeeds.

### 7.9 ⚠️ Two pieces of temporary instrumentation are still live in production code

**1. The A/B kill switch** — `Block/Search.php:61`:

```php
/**
 * TEMPORARY A/B kill switch — flip to true to disable SSR for this exact
 * page … Revert to false once done.
 */
private const AB_DISABLE_SSR = false;

public function getPlpResult(): ?PlpResult
{
    if (self::AB_DISABLE_SSR) { return null; }
    …
```

Currently `false`, so SSR is **ON** for search. This exists only for benchmarking. It also creates a genuine operational hazard, documented from experience: at one point the *deployed server file* had `true` while the local repo had `false`, so what was being tested in the browser did not match what the code said. **Lesson: never treat "the code says X" as proof that "the server is running X".** Verify against the deployed file or the actual HTTP response.

Note there is **no equivalent switch for category** and **none for the Shadow DOM** — the shadow wrap is unconditional.

**2. `[SmartSearch][BENCH]` logging** — three log lines per SSR request, in both `fetchSearch()` and `fetchCategory()`, plus `logTimeSinceRequestStart()` (`FalcoSensePlpProvider.php:195`), which reads `$_SERVER['REQUEST_TIME_FLOAT']` to report how much time Magento burned *before* reaching FalcoSense. All three docblocks say "TEMPORARY … Remove once the module's contribution is confirmed."

They are still there. On a busy site this is **3 INFO lines written to `system.log` on every search and every category page view**, which is real I/O and real log noise. Cleanup decision is still open (§20).

---

## 8. Shadow DOM — what, why, how, and where

### 8.1 WHAT Shadow DOM is

**For a non-technical reader:** a web page's styling is global by default — a CSS rule written for the header can accidentally restyle the footer. Shadow DOM lets a section of the page live inside a sealed bubble. Styles inside don't leak out; styles outside don't leak in. It is the browser's own, built-in version of "don't let these two things break each other" — the same idea as an `<iframe>`, but without an iframe's downsides.

**For a developer:** `Element.attachShadow({mode:'open'})` creates a `ShadowRoot` attached to a host element. Nodes inside it are in a separate tree. Consequences that matter in this codebase:

| Rule | Implication here |
|---|---|
| Document stylesheets do **not** cascade into a shadow tree | The theme's `styles.css` had to be re-`<link>`ed inside (§8.4) |
| CSS **custom properties do** inherit through the boundary | `var(--ahy-card-radius)` still works (§8.6) |
| `document.getElementById()` cannot see inside | Three lookups had to be rewritten (§8.5) |
| `document.querySelector()` cannot see inside | The `alpine:initialized` fallback had to be rewritten |
| Events **retarget** at the boundary | `$dispatch` had to become `window.dispatchEvent` (§8.5) |
| `getRootNode()` returns the `ShadowRoot` from inside it | This is the escape hatch used everywhere (§8.5) |
| `ShadowRoot` implements `DocumentOrShadowRoot` | …so `shadowRoot.getElementById()` works |

**Declarative Shadow DOM (DSD)** is the newer, HTML-only form:

```html
<div id="host">
  <template shadowrootmode="open">
     …content…
  </template>
</div>
```

The **HTML parser** promotes this to a real shadow root *during parsing, before any JavaScript runs*. That property — no JS required — is the entire reason it was chosen here. Support: Chrome/Edge, Firefox 123+, Safari 17.4+.

### 8.2 WHY: the three options, and the decision

Three options were compared explicitly before any code was written.

| Option | Style isolation | Content in initial HTML? | Verdict |
|---|---|---|---|
| **Imperative Shadow DOM** (`attachShadow()` in JS) | ✅ Real | ❌ **No** — content only exists after JS runs | **Rejected.** Defeats SSR entirely; a non-JS crawler sees nothing. |
| **Declarative Shadow DOM** (`<template shadowrootmode>`) | ✅ Real | ⚠️ **Yes, but…** see below | **Accepted for search only, as an acknowledged-risk experiment.** |
| **Light DOM + SSR** (plain HTML, no shadow) | ❌ None (only narrow `!important` guards) | ✅ Yes, unambiguously | **Chosen as the default** — used for category, and for search's non-shadow parts. |

**The "but" on DSD, stated precisely, because this is the live open risk:**

`<template>` exists in HTML specifically so that its content is **not** part of the normal document. A browser that implements the `shadowrootmode` promotion step treats it as page content; a spec-compliant-but-DSD-unaware parser leaves it inert and excluded. Most **non-rendering crawlers** (GPTBot, ClaudeBot, and similar AI/AEO crawlers) fall in the second category. So DSD can reproduce the exact crawler-invisibility failure mode that SSR was built to fix — just relocated from "JS required" to "DSD support required".

Nuance worth knowing: the product names, prices and links *are* present as literal text in the raw HTML source, so a crawler doing naive HTML-to-text extraction still sees them. The risk applies specifically to crawlers that build a proper DOM and then read it — those will find the content in an inert template fragment.

**The decision that follows from this:**

- **Category = the SEO-critical surface = NO Shadow DOM.** Plain light-DOM SSR only.
- **Search = the experiment.** Shadow DOM added deliberately, with the risk understood and accepted, scoped to this one page.

> *"Category deliberately has no shadow DOM — plain SSR only… This was a deliberate choice made when building category's SSR"* — `FALCOSENSE-SEARCH-SSR-HANDOFF.md` §2b

⚠️ **Do not extend Shadow DOM to category without an explicit decision.** It reopens this exact risk on the pages that matter most for SEO.

**And why keep sharing Tailwind at all, rather than isolating properly?** Because this module ships **zero CSS of its own** — every colour, spacing and shape comes from the host theme's compiled Tailwind. Isolating it fully would mean rebuilding the entire visual design from scratch (which is what `Ahy_SmartSearchLuma` did — see §8.8). The CSS hardening here is therefore deliberately narrow: a box-model-only `!important` guard on the one new element (`search/results.phtml:1204`):

```css
#fs-ssr-grid {
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
}
```

> *"This block-model-only !important guards just the one new element against being silently squeezed by a theme layout rule… not a blanket reset."*

The goal was "don't let the *new* SSR markup regress", not "fix a known leak" — there was no CSS-leak bug on this module to begin with.

### 8.3 HOW: what was actually built, line by line

Everything lives in `search/results.phtml`. The boundary opens at line **89** and closes at line **565**.

```php
89   <div id="fs-search-shadow-host">                        ← the host element
90   <template shadowrootmode="open">                        ← the boundary opens
91       <link rel="stylesheet" href="…css/styles.css">      ← re-link the theme's CSS  (§8.4)
92-179   <style> … </style>                                  ← a DUPLICATE of this file's own CSS (§8.4)
180-207  <?php if ($plpResult !== null): ?>
             #fs-ssr-grid  +  #fs-ssr-payload                ← the SSR content (§7.5)
         <?php endif; ?>
209-563  <div x-data="ahySearchResults(…)"> … </div>         ← the ENTIRE Alpine component
564  </template>                                             ← the boundary closes
565  </div>
566-589 <script> attachShadowRoots(document) </script>       ← the fallback shim
591-1121 <script> function ahySearchResults(…) {…} </script>  ← component definition (OUTSIDE)
1123-1218 <style> … </style>                                  ← the ORIGINAL light-DOM CSS
```

**Note what is inside versus outside.** Inside: the SSR grid, the JSON payload, and the whole Alpine component markup. Outside: the `<script>` blocks that *define* `ahySearchResults()`, and the original light-DOM `<style>`. That split is deliberate and correct — function definitions must be in the document scope to be globally reachable.

**The fallback shim** (`:580`) is the standard idempotent DSD polyfill:

```js
(function attachShadowRoots(root) {
    root.querySelectorAll('template[shadowrootmode]').forEach(function (template) {
        var mode = template.getAttribute('shadowrootmode');
        var shadowRoot = template.parentNode.attachShadow({ mode: mode });
        shadowRoot.appendChild(template.content);
        template.remove();
        attachShadowRoots(shadowRoot);   // recurse, for nested DSD
    });
})(document);
```

**WHY it's safe to run unconditionally:** on a browser with native DSD support, the parser already promoted the template and *removed it from the DOM*, so `querySelectorAll('template[shadowrootmode]')` finds nothing — a no-op. On a browser without support, the template is still sitting there inert, and this promotes it manually. It runs **synchronously at parse time**, not on `DOMContentLoaded`, so it finishes before Alpine's bootstrap looks for the component.

### 8.4 WHY the theme stylesheet is re-linked inside the boundary

This is the single most important consequence of adding Shadow DOM to a Tailwind-styled module.

The module's entire visual design is Tailwind utility classes (`text-ahy-blue`, `grid-cols-2`, `rounded-full`) that live in the theme's compiled `css/styles.css`. **A document stylesheet does not cascade into a shadow tree.** Without intervention, every one of those classes would resolve to nothing and the grid would render completely unstyled.

Two mitigations were applied:

1. **`<link rel="stylesheet" href="…css/styles.css">` at `:91`** — loads the theme's compiled Tailwind *inside* the shadow root. The network fetch is deduplicated by the browser cache, but ⚠️ **the CSSOM is built a second time for this root**, which is a real (if modest) parse cost on every search page view.
2. **A duplicate of the file's own `<style>` block at `:92-179`** — because a light-DOM `<style>` tag doesn't reach in either. The original at `:1123-1218` was left in place too.

**⚠️ Correction to a claim in the previous handoff doc.** That doc describes the light-DOM copy as *"harmless (becomes dead/no-op CSS for whatever moved into the shadow root, but still needed for anything that didn't move)"*. That understates its role: the light-DOM copy is **load-bearing**, because of `x-teleport`. See §8.7.

And conversely, part of the *shadow-root* copy is genuinely dead: rules like

```css
body.ahy-search-open .page-layout-2columns-left .columns { … }
```

(`search/results.phtml:93`) can never match inside a shadow root, because there is no `<body>` element in that tree. So the duplication is imperfect in both directions — each copy contains rules that only work in the *other* location.

### 8.5 The four JavaScript fixes the boundary forced

Adding the boundary broke four things. All four are fixed; understanding them teaches the rules.

**Fix 1 & 2 — `document.getElementById()` in `init()`** (`search/results.phtml:642-648`):

```js
// BEFORE (broken):  document.getElementById('fs-ssr-grid')
// AFTER:
const shadowRoot = this.$root.getRootNode();
shadowRoot.getElementById('fs-ssr-grid')?.remove();
…
const payloadEl = isCanonical ? shadowRoot.getElementById('fs-ssr-payload') : null;
```

> *"a plain document.getElementById() can never see into a shadow tree, even one the calling script is itself part of, so every id lookup below goes through this root reference instead of `document`."* — `search/results.phtml:637`

That parenthetical is the key insight for a newcomer: **being inside the shadow tree does not help you.** `document` still means the document. `this.$root.getRootNode()` returns the `ShadowRoot`, which supports `getElementById()` via the `DocumentOrShadowRoot` interface.

**Fix 3 — `_scrollToResults()`** (`:944`):

```js
const el = this.$root.getRootNode().getElementById('search-results-layout');
```

**Fix 4 — the `alpine:initialized` fallback** (`:1110-1120`):

```js
var host = document.getElementById('fs-search-shadow-host');   // the HOST is in the document
var root = (host && host.shadowRoot) ? host.shadowRoot : document;   // then step inside
var el = root.querySelector('[x-data^="ahySearchResults"]');
```

Note the shape: find the host in the document, then step through `.shadowRoot`. The `: document` fallback covers the case where the host somehow isn't there.

**Fix 5 — event dispatch across the boundary.** In `search/product-card-desktop.phtml:119` and `-mobile`:

```js
// BEFORE:  $dispatch('ahy-cfg-modal-open', product)
// AFTER:
window.dispatchEvent(new CustomEvent('ahy-cfg-modal-open', {detail: product, bubbles: true}))
```

**WHY:** the "See Options" button is inside the shadow root; the modal that listens (`modal/config-modal.phtml`) is in the light DOM, appended to `<body>`. Alpine's `$dispatch` relies on bubbling, and event propagation across a shadow boundary involves retargeting and depends on `composed`. Dispatching directly on `window` sidesteps the question entirely. This wasn't invented for the task — the header cards already used this pattern, so it was existing prior art in the same codebase.

⚠️ The equivalent category card templates were deliberately **not** changed, since the category page has no shadow boundary. So `search/product-card-*.phtml` and `category/product-card-*.phtml` now differ on this line. That is correct but non-obvious — leave it alone unless category gains a boundary.

### 8.6 Reference table: what crosses a shadow boundary

Pin this to your monitor before debugging anything on the search page.

| Thing | Crosses in? | Crosses out? | Practical note |
|---|---|---|---|
| Document `<style>` / `<link>` CSS | ❌ | — | Why `styles.css` is re-linked (§8.4) |
| **CSS custom properties (`--foo`)** | ✅ **Yes** (inherited) | — | Why `var(--ahy-card-radius)` still works |
| Inherited CSS (`font`, `color`) | ✅ Yes | — | Text inherits the host's font |
| Shadow-root `<style>` | — | ❌ | Isolation working as intended |
| `document.getElementById/querySelector` | ❌ | — | Fixes 1–4 |
| `host.shadowRoot.getElementById()` | ✅ | — | The correct way in |
| `getRootNode()` from inside | ✅ | — | The escape hatch used everywhere |
| Bubbling DOM events | retargeted at the boundary | depends on `composed` | Fix 5 |
| `window.dispatchEvent` / `addEventListener` | ✅ Always | ✅ Always | The reliable channel — used for `ahy-cfg-modal-open`, `ahy-token-refreshed`, `ahy-modal-search`, `reload-customer-section-data`, `toggle-cart` |
| `x-teleport="body"` | — | ✅ **Escapes** | §8.7 — this one bites |
| IDs / `:root` selectors | scoped per tree | — | The same ID can legally exist in both trees |
| `document.body.classList` | ✅ (it's one body) | ✅ | How `ahy-search-open` still works |

### 8.7 ⚠️ Live consequences and open risks

**Risk 1 — Crawler visibility (accepted, unresolved).** §8.2. Applies to the search results page only.

**Risk 2 — The admin "Columns Per Row" setting silently does nothing on the search page.** *(Newly identified in this review.)*

The chain breaks like this:

- `global-style-vars.phtml:33` emits, in the **light DOM** (`before.body.end`):
  ```css
  @media (min-width: 1280px) {
      .ahy-product-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  ```
- The search page's desktop grid is `class="hidden md:grid grid-cols-2 xl:grid-cols-3 ahy-product-grid"` (`search/results.phtml:512`) — **inside the shadow root**.
- The shadow root receives only `styles.css` and the duplicated results.phtml `<style>`. **Neither contains `.ahy-product-grid`.**
- Therefore the override never applies, and the grid falls back to Tailwind's `xl:grid-cols-3` — **always 3 columns at ≥1280px**, whatever the admin selects.

The `var(--ahy-card-*)` values still work (custom properties inherit), so cards keep their configured radius/border/shadow and the grid keeps its configured gap. Only the **column count** is broken, and only on the search page — category and sliders are unaffected. The admin field's own comment (`system.xml:149`) explicitly promises it *"Applies to the search results, category, and seller profile grids"*, so this is a genuine regression against documented behaviour.

**Fix (one line):** duplicate `global-style-vars.phtml`'s `<style>` content into the shadow root, or move `.ahy-product-grid` into the results.phtml `<style>` block that is already duplicated.

**Risk 3 — `x-teleport="body"` escapes the boundary, and only survives because of the duplicated CSS.**

The mobile filter drawer is declared inside the shadow root but teleported out:

```html
<template x-teleport="body">   <!-- search/results.phtml:247 -->
```

Alpine resolves the teleport target with `document.querySelector('body')` and physically moves the node into the light DOM. Alpine's reactive scope follows it, so the bindings keep working — but its **styling does not**: once in the light DOM it is styled by document CSS, not by the shadow root's.

It renders correctly today **only because** the original light-DOM `<style>` copy at `search/results.phtml:1123-1218` was left in place, and that copy contains `.ahy-filter-sidebar`, `.ahy-filter-title`, `.ahy-filter-chips`, `.ahy-filter-option` and the `.ahy-filters-menu` mobile rules. **If someone "cleans up" that apparently-redundant light-DOM `<style>` block, the mobile filter drawer on the search page will lose its styling.** Add a comment there before that happens.

**Risk 4 — Double CSSOM cost.** `styles.css` is parsed twice per search page view (once for the document, once for the shadow root). Modest, but it is a cost SSR was supposed to be reducing.

**Risk 5 — Debugging is harder.** `document.querySelector('#fs-ssr-grid')` in DevTools returns `null` on a working page. Use:
```js
document.getElementById('fs-search-shadow-host').shadowRoot.querySelector('…')
```

**Risk 6 — No kill switch.** SSR has `AB_DISABLE_SSR`; the Shadow DOM wrap does not. Disabling it means editing the template (remove lines 89–91, 564–565, and the `<style>` duplicate). Consider adding a switch (§20).

**What was verified working** (browser-confirmed, not just code-read): View Source showed the raw `<template shadowrootmode="open">` containing real product HTML and a `#fs-ssr-payload` with `"success":true` and real product/variant data; DevTools' Elements panel showed a genuine `▼ #shadow-root (open)` under `#fs-search-shadow-host` containing the `<link>`, `<style>`, `#fs-ssr-grid` (before Alpine removed it) and the `x-data="ahySearchResults(…)"` component. **Also of note:** an earlier "it works" screenshot turned out to be proving shadow-DOM-wraps-*CSR*, because the deployed file had `AB_DISABLE_SSR = true` at the time. It was re-verified correctly afterwards. When you verify this yourself, confirm the payload contains real data — not just that a shadow root exists.

### 8.8 Contrast: how `Ahy_SmartSearchLuma` does Shadow DOM (for context only)

The sibling module took the opposite approach on every axis. Reading both is the fastest way to understand the trade-off space.

| Aspect | `FalcoSense_Search` (live) | `Ahy_SmartSearchLuma` (disabled) |
|---|---|---|
| Attachment | Declarative only (`<template shadowrootmode>`), plus a fallback shim | **"Detect-then-attach"**: reuses a server-sent DSD root if present, else `attachShadow()`. One code path for both. |
| Scope | Search results page only | Every surface |
| CSS source | Re-`<link>`s the **host theme's** compiled Tailwind | Ships its **own** `BASE_CSS` as a JS string constant |
| Isolation | Deliberately porous | `:host { all: initial }` — total reset |
| Stylesheet mechanism | `<link>` + duplicated `<style>` | **Constructable stylesheets** (`new CSSStyleSheet()` + `adoptedStyleSheets`), cached per distinct CSS text, with a `<style>` fallback |
| Theming | Inherits the theme by sharing its classes | Detects host tokens and injects `--fs-theme-accent` / `--fs-theme-font` (`theme-sync.js`) with Everest values baked in as `var()` fallbacks |
| PHP→JS handoff | A `<script type="application/json">` island | One `data-config` JSON attribute on the mount element (`readConfig()`) |
| Portability | ⚠️ Low — needs the Everest theme (§18) | Higher — carries its own design system |

From `falcosense-shadowdom-module/view/frontend/web/js/widget/shadow-root.js:78`:

> *"A server-rendered Declarative Shadow Root already exists (SSR-Shell) — reuse it. Calling attachShadow() on a node that already has one throws, so this check is load-bearing, not defensive paranoia."*

And on why `open` rather than `closed` (`:86`):

> *"mode: 'open', not 'closed' — the isolation is structural (the browser boundary), not secretive. Keeping it open means devtools/monitoring can still inspect it."*

**Take-away for future work on the live module:** if Shadow DOM is ever extended beyond the search page, the `getOrCreateShadowRoot()` + `adoptedStyleSheets` pattern from the sibling module is the better foundation — it avoids re-parsing a stylesheet per root and removes the duplicated-`<style>` problem entirely.

---

## 9. Search — end to end

### 9.1 Surface 1: the header search takeover overlay

**WHAT it is (non-technical):** typing in the header search box does not open a small dropdown of suggestions. It **replaces the entire page content** with a live search results view, updating as you type, without a page reload. Pressing Enter performs a real navigation to the standalone results page.

**HOW it works, mechanically.** `html/header/search-form.phtml` renders two things:

1. **The input** (`:89-116`), an Alpine component `initMiniSearch()` (`:51`) with `@input.debounce.300="suggest()"`.
2. **The overlay** (`:547-750`), a `<div id="ahyModal">` running the Alpine component `ahyModalSearch()` (`:154`).

The takeover is pure CSS plus a body class (`:754-760`):

```css
#ahyModal { display:none; width:100%; min-height:90vh; background:#efeadd; }
#ahyModal.is-open { display:block; }
body.ahy-search-open .page-main,
body.ahy-search-open main,
body.ahy-search-open #maincontent { display:none !important; }
body.ahy-search-open .top-container { display:none !important; }
```

`openModal()` (`:228`) adds `.is-open` to the overlay, adds `ahy-search-open` to `<body>`, and scrolls to top. `closeModal()` (`:234`) reverses it.

**The typing flow, step by step:**

| Step | Code | What happens |
|---|---|---|
| 1 | `:56-67` `suggest()` | Debounced 300 ms. Below `getMinQueryLength()` → dispatch `ahy-modal-close` and stop. |
| 2 | `:63` | `window.dispatchEvent(new CustomEvent('ahy-modal-search', {detail:{query}}))` |
| 3 | `:64-66` | Builds `/fs/search?q=<term>` and calls `ahySetSearchUrl()` → `history.pushState` |
| 4 | `:214-220` | The overlay's listener resets state if the query changed, opens the modal, calls `fetch()` |
| 5 | `:247-285` | `GET /api/v1/products` with `search_token`, `q`, `page`, `per_page` (12), `include_variants=1`, plus active filters/price/sort. On HTTP 401 → refresh token → retry once |
| 6 | `:269-282` | On success: populate `results`, `total`, `totalPages`, `apiFacets`; `buildFilters()`; if zero results, `fetchPopularProducts()` + `fetchPopularSearches()` |

**Enter key → a real navigation.** The form's `action` is `$block->getUrl('fs/search')` (`:29`, `:91`), and `search()` (`:73-84`) calls `this.$refs.form.submit()`. So Enter leaves the SPA-ish overlay and loads the standalone results page (§9.2).

**The URL/back-button choreography** is the cleverest and most fragile part (`:35-49`, `:237-244`):

```js
window._ahySearchPushCount = window._ahySearchPushCount || 0;
function ahySetSearchUrl(url) { window._ahySearchPushCount++; history.pushState(history.state, '', url); }

window.addEventListener('popstate', () => {
    if (window._ahySuppressPopstateReload) { window._ahySuppressPopstateReload = false; return; }
    window.location.reload();
});
```

Every keystroke-batch pushes a new history entry, so the address bar always reflects the current query and the result is shareable. On close, `closeModal()` rewinds all of them at once with `history.go(-n)`, setting `_ahySuppressPopstateReload` so the resulting `popstate` doesn't trigger the reload. **Any real back-navigation reloads the page** — a deliberate choice, because the overlay's state cannot be reconstructed from the URL alone.

⚠️ **A side effect worth knowing:** typing a 10-character query produces up to 10 history entries. Pressing the browser Back button repeatedly walks back through them, each triggering a full page reload.

**The overlay also re-parents itself into the DOM** (`:208-212`):

```js
const wrapper = this.$el.closest('.columns') || this.$el;
const header = document.querySelector('header') || document.querySelector('.page-header');
if (header && header.parentNode) header.parentNode.insertBefore(wrapper, header.nextSibling);
```

It moves itself to sit immediately after the site header, because it was declared inside the header block but needs to occupy the full content area.

⚠️ Hard-coded behaviours in this file: `fetchPopularProducts()` searches for the literal query **`'outdoor'`** (`:292`) to produce "popular products"; `$endpoint = ... ?: 'http://localhost:8080'` (`:22`) is a leftover dev fallback that other files have had removed; `searchExact()` navigates to `/catalogsearch/result/` (`:340`) rather than `/fs/search`.

### 9.2 Surface 2: the standalone search results page

Reachable at **two** URLs, rendering the identical block tree (§5.3): `/fs/search?q=…` and `/catalogsearch/result/?q=…`.

**The full render sequence for `/fs/search?q=jacket`:**

```
1. Controller\Search\Index::execute()        → sets the page title, returns layout
2. Layout merge: default.xml + fs_search_index.xml
3. Block\Search::getPlpResult()              → PageContext → FalcoSensePlpProvider
                                                → GET /api/v1/products (500 ms budget)
4. search/results.phtml renders:
     a. <div id="fs-search-shadow-host">
     b.   <template shadowrootmode="open">
     c.     <link rel="stylesheet" href="…/css/styles.css">
     d.     <style> (duplicate of this file's CSS)
     e.     #fs-ssr-grid  — real product HTML          ← if $plpResult !== null
     f.     #fs-ssr-payload — the same data as JSON    ← if $plpResult !== null
     g.     <div x-data="ahySearchResults(…)">  … the whole Alpine UI …
     h.   </template></div>
     i. <script> DSD fallback shim </script>
     j. <script> function ahySearchResults(){…} </script>
     k. <style> (light-DOM copy — load-bearing, see §8.7)
5. Browser: parser promotes the template → real shadow root
6. Alpine boots → init() → removes #fs-ssr-grid, seeds from #fs-ssr-payload,
   skips fetch() (canonical view only)
```

**Alpine component `ahySearchResults()` — state** (`:592-624`): `results`, `filters`, `apiFacets`, `activeFilters`, `priceRange`, `priceBuckets`, `activePriceMin/Max`, `activePriceRange`, `total`, `page`, `pageSize` (18), `totalPages`, `sort`, `loading`, `paginationLoading`, `wasCorrection`, `correctedQuery`, `originalQuery`, `suggestedQuery`, `autoBrand`, `bypassSpell`, `cartLoading{}`, `wishLoading{}`.

⚠️ **`pageSize` disagreement.** The Alpine default is **18** (`:614`), the header overlay's is **12** (`:179`), the PHP config default is **12** (`config.xml`), and the fetch fallback if the API omits `per_page` is **50** (`:738`). Four different page sizes for the same catalogue. The API's `pagination.per_page` normally wins, so this rarely bites — but it is why the SSR grid and the hydrated grid can briefly show different card counts.

**Key methods:**

| Method | Line | Notes |
|---|---|---|
| `init()` | 632 | Shadow-aware ID lookups; SSR seeding; canonical gate (§7.5) |
| `fetch()` | 700 | Builds the query, single 401-retry, calls `trackSearch()` on success. If zero results with no filters, dispatches `ahy-modal-search` (`:761`) — reusing the header overlay's zero-results UI |
| `buildFilters()` | 765 | Maps `apiFacets` → `filters`, **preserving each group's `collapsed`/`showAll` state across refetches** (`:772-773`) — a nice touch. Renames facet `brand` → label "Shop By Brand" (`:778`). Drops `price` and `category` facets from the list (handled separately) |
| `sortedResults()` | 801 | ⚠️ **Client-side** price filtering and price sorting — see the warning below |
| `goToPage(p)` | 951 | `history.replaceState` (not push), sets `paginationLoading`, fetches, then scrolls |
| `_animateScrollTo()` | 928 | Hand-rolled rAF scroll animation |
| `_scrollToResults()` | 943 | Shadow-aware lookup of `#search-results-layout` |
| `searchExact(q, bypassSpell)` | 854 | Full page reload with a rewritten query string |
| `trackSearch()` / `trackEvent()` / `trackClick()` | 827 / 839 / 850 | Analytics (§13) |
| `imgUrl()` / `handleImgError()` | 986 / 1006 | Image resolution + two-stage fallback |
| `addSimpleToCart()` / `addToWishlist()` | 1023 / 1046 | Cart/wishlist (§9.6) |

⚠️ **Sorting and price filtering are done twice, in two places, with different semantics.** `fetch()` sends `sort`, `price_min` and `price_max` to the platform (`:724-725`, and category sends `sort` at `category/results.phtml:499`) — **but the search page's `fetch()` never sends `sort` at all**, and `sortedResults()` (`:801-825`) *also* re-filters by price and re-sorts by price **in the browser, over the current page only**. Consequences:

- `setSort()` (`:970`) on the search page only sets a variable — it does **not** refetch. So "Price: Low to High" sorts the 18 products on the current page, not the whole result set. Page 2 restarts the sort. This is very likely not the intended behaviour, and it differs from category, where `setSort()` (`category/results.phtml:632`) resets to page 1 and refetches.
- Price bucket filters shrink the visible card count without adjusting `total` or `totalPages`, so the pagination reads "1,240 items" while showing 3 cards.

**Why `_animateScrollTo()` is hand-rolled** — a genuinely instructive comment (`:918-927`):

> *"Safari's native smooth-scroll can get interrupted/clipped when the page's scrollable height changes mid-animation — which happens here since fetch() briefly hides the grid a frame after we start scrolling — leaving the scroll stuck partway or not moving at all. Re-asserting the target position every frame (rather than handing off to the browser once) behaves identically across Safari, Chrome and Firefox."*

And `goToPage()`'s overlay trick (`:956-963`):

> *"fetch() flips the main `loading` flag, which hides #search-results-layout and collapses the page height out from under the current (possibly deep) scroll position — scrolling to the filters top before or during that collapse just gets clamped back up near scrollY 0. The paginationLoading overlay covers the collapse/re-render visually, and we scroll only after fetch() resolves."*

### 9.3 The search token security model

**WHY it exists:** the browser must call the FalcoSense API directly, so it needs a credential — but the real API key must never reach the browser.

```
Magento (server)                                    Browser
────────────────                                    ───────
api_key (secret, in core_config_data)
      │
      │ POST /api/v1/auth/token   header: X-Api-Key
      ▼
FalcoSense returns {token, expires_at}
      │
      │ cached to /tmp/smartsearch_token_<hash>_<store>.json
      ▼
  token ─────────── embedded in the page ──────────► used as ?search_token=…
                    or served by
                    GET /smartsearch/search/token
```

`Service/SearchTokenService.php`, docblock at `:9`:

> *"Fetches a short-lived search token from the platform and caches it locally. The real API key is only ever sent server-side (PHP → platform). The browser receives only the token."*

**The cache** is a **plain file in `/tmp`**, not Magento's cache (`:16`, `:46`), refreshed 300 s before expiry (`:17`, `:37`).

**A real multi-tenancy bug, already found and fixed** — quoting `:54-64` because it is excellent institutional memory:

> *"/tmp is shared across every Magento install on a given host (dev2, staging, prod, etc. often coexist on the same box under different Linux users) — every fresh install defaults its first store to id=1, so keying only by store id let two totally unrelated sites silently clobber and read back each other's cached token, handing out a token minted under a completely different client's API key. Hashing the API key into the filename makes the cache path unique per site without needing any new site-identity config."*

The fix (`:65-70`) is `/tmp/smartsearch_token_` + `substr(hash('sha256', $apiKey), 0, 16)` + `_` + `$storeId` + `.json`.

**The live-refresh layer, `window.ahyTokenRefresh`** (`html/header/search-form.phtml:119-152`). **WHY it exists:** the header block is FPC-cacheable, so a token baked into cached HTML will eventually be expired when served. Every Alpine component therefore calls `window.ahyTokenRefresh.get()` before its first fetch and retries once on HTTP 401. It de-duplicates concurrent calls with an `inflight` promise and broadcasts `ahy-token-refreshed` on `window` so every component on the page updates at once.

⚠️ Three notes: (a) `ahyTokenRefresh` is defined **only** in the header search form, so any page without the header search block has components that call `window.ahyTokenRefresh?.get()` and silently get `undefined` — they then use whatever token was embedded server-side. (b) `Controller\Search\Token` accepts a `force=1` parameter from the client (`:126` of the template) but **`Token::execute()` never reads it** — a `refresh()` returns the same file-cached token unless it is genuinely near expiry. (c) `Token::execute()` passes the *platform* store ID into `getToken()` (`Controller/Search/Token.php:38-39`) where every other caller passes a *Magento* store ID, so on a multi-store site it can read a different cache file than the one the page was rendered with.

### 9.4 Spell correction, "did you mean", and `bypass_spell`

The platform auto-corrects misspellings and reports what it did. Three response fields drive three UI states:

| Response field | State | UI |
|---|---|---|
| `was_corrected` + `corrected_query` + `original_query` | `wasCorrection = true` | *"Showing results for **jacket** instead of *jakcet*. Do you still want to search for "jakcet"?"* — `search/results.phtml:410-419` |
| `suggested_query` (and not corrected) | `suggestedQuery` | *"Did you mean "jacket"?"* — `:421-426` |
| neither | — | nothing |

**`bypass_spell` and the loop it prevents** — this is a subtle bug fix worth understanding (`:854-863`):

```js
searchExact(q, bypassSpell = false) {
    const params = new URLSearchParams(window.location.search);
    params.set('q', q);
    if (bypassSpell) { params.set('bypass_spell', '1'); } else { params.delete('bypass_spell'); }
    window.location.search = params.toString();
}
```

> *"bypassSpell is only true for the 'Do you still want to search for X' link (the shopper's own original, uncorrected term) — it tells the platform to skip auto-correction entirely instead of just re-running the same correction on reload and showing this same prompt again."* — `:857-860`

Without it, clicking "search for *jakcet* anyway" reloads with `q=jakcet`, the platform corrects it to `jacket` again, and the same prompt reappears — an infinite loop from the shopper's point of view.

`bypass_spell` is then persisted across the reload (`:661`), sent on the fetch (`:716`), **and included in both canonical-view checks** (PHP: `PageContext.php:103`; JS: `:667`) so a bypassed search is never served from the SSR payload. That PHP/JS alignment was a separate bug fix (commit `49b14a9`).

### 9.5 Facets, filters, and pagination

**Facets come from the platform**, not from Magento's layered navigation (which was explicitly removed from layout, §5.4). The wire shape is `{key, label, options:[{value,count}], min?, max?}`, mapped by `PlpFacet` server-side and by `buildFilters()` client-side.

**Filter UI** (`search/filters-desktop.phtml`): each group is a collapsible checkbox list, capped at 5 options with a "See More +" toggle (`:51`, `:77-83`). Selected options are re-sorted to the top on the next refetch (`search/results.phtml:781-785`). Active filters render as removable chips (`:349-359`).

**Multi-value filter encoding** — an unusual choice (`:723`):

```js
Object.entries(filterMap).forEach(([key, vals]) => url.searchParams.set(key, vals.join('\x1F')));
```

`\x1F` is the ASCII **Unit Separator** control character. It is used as the multi-value delimiter so a filter value containing a comma or pipe cannot break the encoding. If you are reading platform-side logs, that is what the `%1F` in query strings is.

**Price filtering is bucketed, not a slider** (`:885-914`). Six fixed buckets (`Under $50` … `Over $1,000`), filtered down to only those overlapping the facet's reported `min`/`max`. Selecting one is mutually exclusive (`togglePriceRange`). ⚠️ The bucket boundaries and the `$` currency symbol are hard-coded — this is USD-only.

**Pagination** is server-side (real `page`/`per_page` requests) with a page-number dropdown rather than numbered links (`:533-549`). `goToPage()` uses `history.replaceState`, so paging does **not** add history entries. Combined with §9.2's client-side sorting, this means a deep-linked `?p=3&sort=price_asc` URL is *not* SSR'd (it fails the canonical gate) and its sort applies only to page 3's own 18 rows.

### 9.6 Add to cart and wishlist from a results card

Neither uses Magento's normal form-post flow; both are `fetch()` calls against Magento's own controllers, then Hyvä event broadcasts to refresh the UI.

**Add to cart** (`:1023-1044`):

```js
const params = new URLSearchParams({ product: pid, qty: 1, form_key: hyva.getFormKey() });
const resp = await fetch('<?= $addToCartUrl ?>', {
    method: 'POST', body: params,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
               'X-Requested-With': 'XMLHttpRequest' },
});
if (resp.ok || resp.redirected) {
    window.dispatchEvent(new CustomEvent('reload-customer-section-data'));
    window.dispatchEvent(new CustomEvent('toggle-cart'));
    window.dispatchMessages([{ type: 'success', text: 'Product added to cart.' }], 3000);
    this.trackEvent({ event_type: 'add_to_cart', … });
}
```

The three Hyvä contracts being used: `hyva.getFormKey()` (CSRF token), `reload-customer-section-data` (refresh the minicart), `toggle-cart` (open the drawer), `window.dispatchMessages()` (toast). These are **hard dependencies on the Hyvä theme** and a key portability blocker (§18).

⚠️ `resp.ok || resp.redirected` is checked, but a Magento add-to-cart failure (out of stock, required option) typically returns a **302 redirect to the product page carrying an error message** — which satisfies `resp.redirected` and is therefore reported as success. The shopper sees "Product added to cart." for a failed add. The slider version is worse: its `.catch(function(){})` swallows errors entirely (`slider/smart-slider.phtml:247`).

**Wishlist** (`:1046-1067`) posts to `wishlist/index/add/` with `form_key` + `uenc=hyva.getUenc()`, and follows a redirect (the guest-login case) by assigning `window.location.href`.

### 9.7 Zero results

`search/zero-results.phtml` is a child block rendering an `ahyZeroResults()` component (`search/results.phtml:1071-1105`) that fetches popular products and popular searches.

Its docblock explains a real DOM-scoping dependency (`:5-9`):

> *"Rendered as a child block in the exact same DOM position it previously occupied inline, so its nested Alpine component (ahyZeroResults) still inherits imgUrl()/isFallbackImg()/handleImgError() from the parent ahySearchResults() scope via normal DOM-based scope resolution."*

**Lesson for a fresher:** Alpine scope is *lexical in the DOM*. A nested `x-data` inherits its ancestors' methods. Moving this block elsewhere in the layout would silently break its images.

⚠️ Two problems with this file:
- `$popularUrl` and `$suggestUrl` are built by regex-replacing `/api/search.php` → `/api/popular.php` / `/api/suggest.php` on `getSearchUrl()` (`:11-12`). Those `.php` endpoints are a **legacy naming scheme** — the current platform serves `/api/v1/*`. If `search_url` isn't set to a URL ending in `/api/search.php`, the regex doesn't match and the URLs are simply `search_url` verbatim.
- The "popular searches" links point at `/catalogsearch/result/?q=` (`:60`), not `/fs/search`.
- The zero-results modal config (`smart_search/no_results_modal/*`: title, subtitle, heading, product count) has five getters in `Helper/Data.php:209-233` and **zero callers**. The panel's copy is hard-coded in the template instead.

### 9.8 The configurable-product options modal

**WHAT:** a product with variants (size/colour) can't be added to the cart from a grid — the shopper must choose. Clicking "See Options" opens a modal.

**WHERE it's declared:** once, site-wide, in `default.xml:53-58`. It then **re-parents itself to `<body>`** on init (`config-modal.phtml:74`), so its layout position is irrelevant.

**The naming war story** — quote this to any developer who asks why the names are so specific (`config-modal.phtml:8-17`):

> *"Deliberately uses its own event name and Alpine component name (ahySmartSearchCfgModal, not ahyCfgModal) rather than the shared `ahy-open-config-modal` event/`ahyCfgModal()` function that slider/results.phtml, slider/smart-slider.phtml, html/header/search-form.phtml, and a legacy Magento_Theme-level template override also use — those still coexist on the page today (a separate, pre-existing cleanup item), and since JS function declarations with the same name overwrite each other globally, sharing a name meant this modal's markup could end up paired with a DIFFERENT one of those implementations' JS (or vice versa), which is what caused the blank main image reported after the first extraction."*

**🔴 And that exact class of bug is live right now.** The modal listens for `ahy-cfg-modal-open` (`config-modal.phtml:77`). The collection grid dispatches the *other* name:

```html
<!-- collection/results.phtml:131 (desktop) and :196 (mobile) -->
<button @click.prevent="$dispatch('ahy-open-config-modal', product)">
```

The only listeners for `ahy-open-config-modal` are in the orphaned `category/search-form.phtml:1221` and inside `smart-slider.phtml:451` — which sits in a dead `if (false)` block. **So on a Collection page, "Options" does nothing.** One-line fix: rename the event in both lines of `collection/results.phtml`. The sliders and the search/category cards all dispatch the correct name and work.

**What the modal does** (`openModal(stub)`, `:80-178`), in four stages, with a `_requestSeq` guard checked after every `await` so a slow first click can't clobber a faster second one:

1. **Fetch full detail** — `GET /api/v1/product?search_token=…&product_id=…`.
2. **In parallel:** the parent's image gallery (`/ahy_themecustomization/index/mediaGallery?sku=…`) **and** the real Magento configurable attributes (`/smartsearch/configurable/options?product_id=…`).
3. **Merge**, overriding the platform's guessed labels, then auto-select the first available value of each attribute group so Add-to-cart is immediately usable.
4. **In the background**, fetch every variant's images and append them to the thumbnail strip.

**WHY step 2's second call exists** — `Controller/Configurable/Options.php:15-39` is the best-documented class in the module. Paraphrased:

- The FalcoSense API's `configurable_attributes` are **not real attribute data**: the platform *guesses* them by splitting a variant's flattened display title on `" / "` and heuristically classifying each token as Size vs Colour. Any token that isn't a recognised size or colour word — e.g. numeric waist/inseam values like `"28"`, `"34"` — falls through to a hard-coded `return 'Color'` default, producing bogus dimensions like **`Color2`/`Color3`**.
- The previous workaround was **`pub/variant-attrs.php`**, a standalone script outside Magento's bootstrap that hard-coded DB credentials, ran raw SQL with no auth or ACL, and was blocked by an nginx 403 on production (it worked on dev2 only because that domain's blanket HTTP Basic Auth let the request through first).

The controller replaces both with `getConfigurableAttributesAsArray()` + `StockRegistryInterface` — real labels, real per-variant stock. ⚠️ References to `/variant-attrs.php` still exist in the dead block at `smart-slider.phtml:469`.

### 9.9 Which search path does a shopper actually take?

```
        Shopper clicks the header search box and types "jacket"
                              │
                    ┌─────────┴──────────┐
                    │  Types ≥ minLength │
                    └─────────┬──────────┘
                              ▼
                 HEADER TAKEOVER OVERLAY (§9.1)
                 • pure client-side, no SSR
                 • URL pushed to /fs/search?q=jacket
                 • page content hidden via body.ahy-search-open
                              │
              ┌───────────────┼────────────────┐
              ▼               ▼                ▼
        Presses Enter    Clicks a product   Presses Escape
              │               │                │
              ▼               ▼                ▼
   /fs/search?q=jacket    Product page    closeModal(): history.go(-n),
   → REAL PAGE LOAD                        content reappears
   → Controller\Search\Index
   → SSR (canonical only) + Shadow DOM  (§9.2, §7, §8)
              │
   ┌──────────┴───────────┐
   ▼                      ▼
Applies a filter,     Just looks
sorts, or pages
   │                      │
   ▼                      ▼
Fails the canonical   SSR payload seeds
gate → Alpine         Alpine → NO fetch
fetch()es live
```

---

## 10. The category page (PLP)

The category page is architecturally the **same idea as search with three deliberate differences**. Everything in §7 applies; this section covers only what differs.

**Difference 1 — Magento's own controller still runs.** There is no FalcoSense category controller. The module hooks in purely via `catalog_category_view.xml`, which removes `category.products` and the `sidebar.main` container and inserts `Block\Category`. So Magento still resolves the category, still builds breadcrumbs and the page title, still runs its own layer navigation resolution — it just doesn't render a product list.

**Difference 2 — SSR is unconditional** (§7.4). Every page number, every sort order. The gate that search has does not exist here.

**Difference 3 — no Shadow DOM.** The SSR grid and the Alpine component sit in the light DOM, so all ID lookups use plain `document` (`category/results.phtml:426`, `:437`) and no CSS re-linking is needed.

**The Alpine component** `ahyCategoryResults()` (`:389-724`) is a near-copy of `ahySearchResults()` minus the search-specific parts (no spell correction, no `trackSearch`/`trackClick`, no `bypassSpell`, no `autoBrand`) and plus one addition: it sends `category` and `category_ids`.

**Why `category_ids` is sent alongside `category`** (`:475-480`):

> *"Two categories can share the same name (e.g. a 'Featured Products' subcategory exists under both 'Boating Gear' and 'Hunting Gear'), so the name-only filter above matches every same-named category's products combined. Sending category_ids scopes the match to this exact category — the API prefers it over the name filter when both are present."*

The PHP SSR path mirrors this exactly (`FalcoSensePlpProvider.php:140-145`), which is what keeps server and client in sync by construction.

**Sorting differs from search, and category's is correct.** `category/results.phtml:632` — `setSort(val) { this.sort = val; this.page = 1; this.fetch(); }` — resets to page 1 and refetches, and `fetch()` sends `sort` to the platform (`:499`). Search's `setSort()` does neither. If you are fixing the search sorting bug (§9.2), category is the reference implementation.

**⚠️ The image-host inconsistency is category-specific and worth repeating** (§6.3): `category/results.phtml:650,656` hard-code `https://static.everest.com/…` while the PHP SSR renderer emits relative `/media/…` paths. On the category page the SSR card and the hydrated card request the same image from **two different hosts**. On dev2 this is worse than a cache miss — `base_url` is `dev2.everest.com` and `base_media_url` is a CloudFront host, so `static.everest.com` is a third host that only production images live on.

**⚠️ A large CLS mitigation that is also a footgun** (`:765`):

```css
.catalog-category-view .column.main { min-height: 4500px; overflow-anchor: none; }
```

Plus an `x-effect` that sets `min-height: 4500px` while `loading` and `auto` afterwards (`:96`). **4500 px** of reserved height prevents layout shift during hydration but means an empty or short category renders a very tall blank page until Alpine settles. Search uses 1900 px for the same purpose (`search/results.phtml:75`, `:211`).

**What category is missing that search has:** no click tracking (`trackClick`), no search analytics, no `zero_results` popular-products panel wired to the platform, and no `x-teleport` complication (its mobile filter drawer does use `x-teleport="body"` at `:132`, but with no shadow boundary to escape it is unremarkable).

---

## 11. Sliders and collection widgets

The module ships **three** "row/grid of products" widgets that look similar but are built completely differently. Only one of them has any admin documentation, and that documentation is unreachable.

| # | Widget | Block | Template | Rendering |
|---|---|---|---|---|
| 1 | **Smart Slider** | `Block\Slider\Products` | `slider/smart-slider.phtml` | **Server-side PHP** |
| 2 | **Collection Slider** | `Block\Slider\Collection` | `slider/collection.phtml` | **Server-side PHP** |
| 3 | **Collection Grid page** | `Block\Collection` | `collection/results.phtml` | **Client-side Alpine** |

**Business purpose:** merchandising surfaces — trending, newest, most-popular, brand spotlights, curated seasonal collections. The platform ranks by `ranking_signals.popularity_score` / `trending_score` / `meta.indexed_at` (`Block/Collection.php:13-17`). The Smart Slider additionally personalises by the shopper's US state.

### 11.1 ⚠️ Where they appear cannot be determined from the code

Placement lives in the **Magento database** (CMS blocks / CMS pages / layout updates), not in the filesystem. **No layout XML in this module or the theme declares any of these three blocks.** To find where they are actually used:

```sql
SELECT block_id, identifier, title FROM cms_block
 WHERE content LIKE '%FalcoSense%Slider%' OR content LIKE '%FalcoSense%Collection%';
SELECT page_id, identifier FROM cms_page
 WHERE content LIKE '%FalcoSense%' OR layout_update_xml LIKE '%FalcoSense%';
```

### 11.2 How a merchant places one

**Smart Slider** — a raw `{{block}}` CMS directive (there is no `widget.xml` in this module, so it is *not* a Magento Widget). Generated by `Block/Adminhtml/System/Config/SliderInfo.php:48`:

```
{{block class="FalcoSense\Search\Block\Slider\Products" slider_type="YOUR_SLUG_HERE" template="FalcoSense_Search::slider/smart-slider.phtml"}}
```

The documented merchant workflow (`SliderInfo.php:26-44`): FalcoSense platform admin → Sliders → Create Slider (title, sort strategy, optional brand/category filter) → copy the blue **Slug** badge → paste into a Magento CMS block, replacing `YOUR_SLUG_HERE`.

**`slider_type` is the only parameter** (`Block/Slider/Products.php:55`, default `'newest'`). Everything else — title, sort, product list, limit — is decided platform-side.

🧊 **The instruction panel renders nowhere.** `SliderInfo.php` is a `frontend_model` for a system-config field, but no field in `etc/adminhtml/system.xml` references it, and there is no `<group id="sliders">`. The four config paths `smart_search/sliders/slider_1_slug`…`_4_slug` and `Helper\Data::getSliderSlugs()` are all dead too. So the only documentation of how to use the module's flagship merchandising feature is in an unreachable admin block.

**Collection Slider** — layout XML or CMS directive:

```
{{block class="FalcoSense\Search\Block\Slider\Collection" template="FalcoSense_Search::slider/collection.phtml" brand="Garmin" sort="popularity" limit="12" title="Top Garmin Products"}}
```

| Parameter | Read at | Default | Sent as |
|---|---|---|---|
| `brand` | `Block/Slider/Collection.php:67` | omitted | `brand` |
| `category` | `:68` | omitted | `category` |
| `attribute_name` / `attribute_value` | `:69-70` | omitted | same |
| `sort` | `:71` | `popularity` | `sort` — documented values `popularity\|trending\|newest\|price_asc\|price_desc`, **not validated** |
| `limit` | `:72` | `12` | `limit` |
| `price_min` / `price_max` | `:73-74` | omitted | same — **undocumented in the class docblock** |
| `title` | `:101` | auto-generated | display only |

Auto-title (`:99-114`), in priority order: explicit `title` → `"{brand} PRODUCTS"` → `"{category}"` → `"{attribute_value} PRODUCTS"` → by sort (`trending`→`TRENDING NOW`, `newest`→`NEW ARRIVALS`, else `FEATURED PRODUCTS`). Always uppercased.

**Collection Grid page** — layout XML only, no directive documentation anywhere:

| Parameter | Read at | Default | Notes |
|---|---|---|---|
| `sort` | `Block/Collection.php:62-63` | `popularity` | **Whitelisted** to `popularity\|trending\|newest` |
| `heading` | `:66-69` | `Products` | Rendered as the `<h1>` |
| `banner_image` | `:72-75` | `''` | Hero background; injects an `!important` rule at `collection/results.phtml:11-17` |

### 11.3 Data flow

**Both sliders are fully server-side, and both bypass the module's own HTTP client:**

```
CMS block parses {{block}}
  └─ Block\Slider\Products::getSliderProducts()          [Products.php:123]
       ├─ platform base = endpoint_url with #/api/v1/ingest/products.*# stripped  [:133]
       │    ⚠️ fallback if empty: https://app.falcosense.com                       [:135]
       ├─ getCustomerGeoState()                                                    [:65-121]
       └─ raw curl GET {base}/api/v1/sliders/{slug}?api_key={key}&geo_state={state}
            CURLOPT_TIMEOUT 5, CURLOPT_CONNECTTIMEOUT 3                            [:138-148]
  └─ non-200 or empty → [] → template `return`s, renders nothing                   [:153-156]
```

⚠️ **Three problems in that flow:**

1. **The raw API key travels in the query string** (`:139-140`). It is a server-to-server call so it never reaches the browser, but it lands in the platform's access logs and any intervening proxy log. Every other browser-facing call in the module uses the short-lived token instead. The events path does it correctly, as a header (`CustomerEventService.php:251`).
2. **Failure is completely silent** — no logging at all. If the platform is down or the key is wrong, the section vanishes from the page with nothing in `var/log`. You cannot diagnose a missing slider from logs; you must reproduce the `curl` by hand.
3. **A missing `endpoint_url` falls back to `https://app.falcosense.com`** rather than erroring — i.e. a different tenant's platform.

**Block caching** (`Products.php:181-199`): 120 s lifetime, key `ahy_slider_{type}_{md5(geoState)}`, tags `['ahy_slider','ahy_slider_{type}']`. The Collection Slider's key (`Collection.php:123-133`) **excludes `price_min`/`price_max`**, so two collection sliders differing only by price range collide in the block cache and serve each other's HTML.

**The Collection Grid page** is client-side: PHP prints `window._ahyCollInit = {url, token, sort, storeId}` (`collection/results.phtml:18-25`) and Alpine's `ahyCollectionResults()` fetches `GET /api/v1/products/collection?search_token=…&sort=…&limit=24`. **`limit` is hard-coded to 24** (`:262`) with **no pagination, no load-more, no infinite scroll**. The page is capped at 24 products.

### 11.4 Slider mechanics

**Library: Swiper 11** — and ⚠️ **the module never loads it.** The only `swiper-bundle.min.js` script tags in the whole repo are in the theme's PDP template and one unrelated `Ahy_ThemeCustomization` static block. Both sliders defend with a poll:

```js
if (typeof Swiper === 'undefined') { setTimeout(init_<sliderId>, 100); return; }
```
(`smart-slider.phtml:257`, `slider/collection.phtml:247`)

**On a page where Swiper is never loaded, this polls at 10 Hz for the life of the page and the slider never initialises.** Cards still render — they just sit as an un-swipeable overflow-hidden row. This is the most likely "the slider doesn't work" ticket. Fix: declare the Swiper asset in the module's own layout.

**Cards per view** — CSS media queries on `.swiper-slide` width plus `slidesPerView: 'auto'`; identical in both templates:

| Viewport | Slide width | Cards |
|---|---|---|
| ≤ 639 px | 100% | 1 |
| 640–1023 px | 50% | 2 |
| 1024–1279 px | 33.33% | 3 |
| ≥ 1280 px | 25% | 4 |

The admin "Columns Per Row" setting does **not** apply here, and `system.xml:149` says so.

**No arrows, no dots.** Navigation is drag / trackpad / keyboard / a draggable scrollbar. The Smart Slider is well-tuned (`freeMode` with momentum ratios, `roundLengths`, `mousewheel` with `forceToAxis` and a 5 px jitter deadzone, `keyboard`, `touchReleaseOnEdges` — `:258-286`). The Collection Slider is a stripped subset with **no momentum tuning and `mousewheel: false`** (`:248-260`) — trackpad horizontal swipe does nothing there. Same widget, materially different feel; the divergence looks unintentional.

**Lazy loading:** sliders use native `loading="lazy"`. The Collection Grid does the opposite — `loading="eager"` + `fetchpriority="high"` on all 24 desktop cards (`collection/results.phtml:81-82`), which eagerly fetches 24 full-size images on load. Worth revisiting for LCP.

**Add to cart from a slider:** `window.ahySliderATC(btn)` (`smart-slider.phtml:230-252`), duplicated **byte-for-byte** in `slider/collection.phtml:208-230`. It reads `data-product-id` / `data-atc-url`, posts `product`/`qty`/`form_key`, then dispatches `reload-customer-section-data` + `toggle-cart` + `dispatchMessages`. ⚠️ It sends **no `X-Requested-With` header** and ends in `.catch(function(){})` — failures are swallowed silently.

**Configurable products from a slider** — two functionally identical mechanisms with different names and different data transport:
- Smart Slider: PHP emits a registry `window.__ahySliderCfg['{sliderId}'] = [...]` (`:296`); the button carries only `data-cfg-pid`; `ahySliderOpenModal()` looks it up and dispatches `ahy-cfg-modal-open` ✅.
- Collection Slider: the **entire product JSON is inlined into the button's `data-product` attribute** (`:184`) and re-parsed on every click; `ahySliderOpenOptions()` dispatches the same event ✅.

⚠️ **Two sliders of the same type on one page half-break.** `$sliderId` is derived purely from `slider_type` (`smart-slider.phtml:10`), so duplicate slugs produce duplicate DOM ids: only the first gets its width rules and its scrollbar, `new Swiper('.<sliderId>-swiper')` initialises only the first match, and the second block's `window.__ahySliderCfg` entry **overwrites** the first — so the first slider's Options buttons look up products in the wrong list. Different slugs are fine. `SliderInfo.php:56-60` tells merchants multiple sliders per page are supported; that is only true with distinct slugs.

### 11.5 ⚠️ Server-side sliders + Full Page Cache = a personalisation hole

`Block\Slider\Products::getCacheKeyInfo()` correctly varies the **block** cache by `geo_state`. But sliders live inside CMS blocks on ordinary CMS pages, and **nothing marks those pages `cacheable="false"`** (in stark contrast to the module's own search/category layouts, §7.6). Under FPC/Varnish, **the first visitor's geo-personalised slider HTML is cached and served to every subsequent visitor of that page.** The block-level cache key is irrelevant once FPC holds the whole page.

Related: the 5 s timeout on a synchronous render-path call is generous, and the calls are **sequential** with no concurrency. A page with four sliders on a cold cache with a slow platform can add up to ~20 s to TTFB. There is no circuit breaker. Contrast the SSR path, which budgets 500 ms and abandons (§7.2).

### 11.6 Other slider/collection issues

- 🔴 **The Collection Grid's "Options" button is wired to nothing** — §9.8. One-line fix.
- 🧊 **`smart-slider.phtml:313-576` is a dead `if (false)` block** — **263 lines, 46% of the file** — containing an obsolete options drawer. Inside it are references a new developer will misread as live: `fetch('/variant-attrs.php?skus=…')` (`:469`, the insecure standalone script the module replaced), `/ahy_themecustomization/index/mediaGallery` (`:461`), a hard-coded `/shipping-returns` link (`:362`). It does contain one genuinely valuable comment (`:544-547`) explaining that a fully-selected configurable must be added against the **parent** product with real `super_attribute[...]` options, or Magento creates a separate simple-product quote line — that insight should be moved to the live modal before deleting the block.
- 🔴 **`frontend_enabled` does not disable the sliders.** `system.xml:29` promises it "Controls the custom search overlay, category page, **and product sliders**". None of the three widget blocks calls `isFrontendEnabled()`. The disabled sibling module *does* (`code/Ahy/SmartSearchLuma/Block/Slider/Products.php:37-40`); the check was lost in the port. Turning the frontend off leaves every slider still calling the platform.
- 🟠 **The "(N Items)" counter on the Collection Grid never displays.** `collection/results.phtml:41` combines Tailwind's `hidden` class with `x-show`. When `x-show` becomes true, Alpine clears the inline `display:none` and the `hidden` **class** takes over. Permanently invisible. Remove the class.
- 🟠 **Guest geo-personalisation silently doesn't happen** — see §13.4.
- 🟠 **Prices are formatted three ways.** Sliders hard-code `'$' . number_format($p, 2)` (`smart-slider.phtml:176`) — USD-only, no currency switching, no locale. The Alpine grids use the currency-aware `hyva.formatPrice()`. A multi-currency store shows wrong prices in sliders.
- 🟠 **`getProductUrl()` hard-codes the `.html` suffix** and a root-level product URL (`Products.php:176-179`, `Collection.php:116-119`, and in JS at `collection/results.phtml:73`). Both assumptions are store-configurable in Magento.
- 🟠 **Duplication:** ~50 lines of `.msi-*` CSS, the entire `ahySliderATC` function, the Swiper init block, and the `$falcosenseImgUrl` closure are each duplicated between the two slider templates, with small unintentional divergences (e.g. the two `$falcosenseImgUrl` copies differ in their no-match behaviour: `basename($image)` vs `''`). The `.msi-*` prefix is legacy ("more seller items") and `ku*` class names are **Klevu** leftovers.
- 🟠 **`getSearchToken()` receives the wrong kind of store ID** at `collection/results.phtml:4` — `getPlatformStoreId()` returns a positional ordinal, not a Magento store ID. Invisible on a single-store site; wrong scope resolution and wrong token cache file on a multi-store one. The same mistake appears at `config-modal.phtml:36-37`.

---

## 12. The product sync pipeline (Magento → FalcoSense)

### 12.1 🔴 Read this first: there is no cron

`etc/crontab.xml` is **empty**. Verbatim:

```xml
<!-- Cron jobs removed: falcosense_search_delta_sync and falcosense_search_image_compress
     are no longer scheduled. Product sync now runs only via the manual CLI command:
     bin/magento smartsearch:sync:full -->
<group id="default">
</group>
```

**Consequences a new developer must internalise:**

- `Cron/ProductSync.php` — **362 fully-working lines** — never executes.
- `Cron/ImageCompress.php` never executes.
- Every log message and admin comment promising "cron will sync" is now false: `ProductSaveObserver.php:55`, `StockChangeObserver.php:74`, `system.xml:42`, `SyncStatus.php:39`.
- **If real-time sync is disabled and nobody runs the CLI, nothing syncs at all.** And real-time sync is **off by default** (§5.5, trap 1).
- The whole `smart_search/cron/full_sync_requested` flag mechanism is inert: it is read only by the unregistered cron, and `Helper\Data::requestFullSync()` has zero callers.

The original schedule expression survives only in an ignored `.bak` file; the removed job IDs were `falcosense_search_delta_sync` and `falcosense_search_image_compress`.

### 12.2 The five entry points, and where they converge

Everything funnels into **`Service/ProductSyncService.php`** (997 lines), the only class that makes an outbound ingest call.

```
              ┌──── etc/events.xml (global area) ────┐
catalog_product_save_after    cataloginventory_stock_item_save_after    catalog_product_delete_after
        │                                  │                                    │
        ▼                                  ▼                                    ▼
 ProductSaveObserver              StockChangeObserver                  ProductDeleteObserver
  gate chain (§12.3)              qty/is_in_stock delta?               loops every store with an API key
        │                                  │                                    │
        └──────────────┬───────────────────┘                                    │
                       ▼                                                        ▼
        ProductSyncService::sync()                              ProductSyncService::delete()
        + syncParentConfigurable()  (re-syncs each parent found
          in catalog_product_relation)
                       │
                       ▼
              normalize()  (§12.5)
                       │
        ┌──────────────┼──────────────────────┐
   status==2 OR    isOverpriced()          normal
   in_stock=false  (>90000)                   │
        ▼              ▼                      ▼
    delete()        SKIP silently      POST {batch_id, store_id, realtime:true, products:[doc]}
                                              │
                                              ▼
                        endpoint_url  (e.g. https://host/api/v1/ingest/products)
                        Headers: X-Api-Key, X-Signature: sha256=HMAC(body, apiKey)

──────────── BATCH PATHS ────────────
Admin "Sync All Products Now"  →  POST smartsearch/sync/fullsync
   → guards + SyncLockManager::acquire('admin')  (60 s pre-lock)
   → shell_exec("env -i … php bin/magento smartsearch:sync:full --force --store=N &")
   → admin polls smartsearch/sync/status every 4 s

CLI  bin/magento smartsearch:sync:full [--force] [--store=N] [--page=P]
   → acquireOrTakeOver('command')
   → per store: precompute 4 exclusion sets, then 5 passes (§12.4)

🧊 DEAD:  Cron/ProductSync.php (unscheduled) · Service/FullSyncService.php (0 callers)
          · both RabbitMQ topics (declared, wired, never published to)
```

### 12.3 Real-time (incremental) sync

**Observers** (`etc/events.xml`):

| Magento event | Observer | Lines |
|---|---|---|
| `catalog_product_save_after` | `Observer\ProductSaveObserver` | `etc/events.xml:6-9` |
| `cataloginventory_stock_item_save_after` | `Observer\StockChangeObserver` | `:12-15` |
| `catalog_product_delete_after` | `Observer\ProductDeleteObserver` | `:26-29` |

**`ProductSaveObserver`'s gate chain** (`:36-87`) — any one of these ends the request silently, with a log line:

1. Null product / no ID (`:41`)
2. `!isEnabled($storeId)` (`:49`)
3. `!isRealtimeSyncEnabled($storeId)` (`:54`)
4. **Rate limit** — hard-coded `RATE_LIMIT_PER_MINUTE = 120` (`:23`), counted in the **Magento config cache** under key `smartsearch_observer_rate_<storeId>_<YmdHi>` with a TTL of `65 - date('s')` (`:123-139`). Above 120 saves/minute for a store, further real-time syncs are dropped entirely.
5. **Change relevance** — `stock_data` present **OR** `AttributeChangeDetector::hasRelevantChange()` (`:68-75`)

**`AttributeChangeDetector` is only 33 lines and watches only five attributes** (`Model/AttributeChangeDetector.php:10`):

```php
private const WATCHED = ['name', 'price', 'special_price', 'status', 'url_key'];
```

⚠️ So `visibility`, `description`, `image`, category assignments, brand, and **every custom EAV attribute** do not trigger a real-time sync via the detector — even though `normalize()` sends all of them. In practice the admin product form always posts `stock_data`, which short-circuits the detector at `:68`, so admin saves always sync. **The detector effectively only gates programmatic saves** (imports, `ProductRepository::save()` without stock data).

**`syncParentConfigurable()`** (`ProductSaveObserver.php:89-121`, mirrored in `StockChangeObserver.php:103-134`) joins `catalog_product_relation` to find every configurable parent of the saved product and re-syncs each one. **WHY:** variants are embedded inside the parent's document, so a child's price/stock change must re-push the parent or the parent's embedded copy goes stale. This one fact drives most of the exclusion logic in §12.4.

**`ProductDeleteObserver` is deliberately different** (`:68-73`): **not** gated on `realtime_sync_enabled` and **not** rate-limited, because a missed delete leaves a purchasable-looking phantom product on the platform indefinitely. It also loops *every store with an API key* rather than the product's own stores, because `AbstractDb::delete()` removes `catalog_product_website` rows **before** dispatching the event, so store resolution would return empty.

### 12.4 Full sync (the CLI)

**Command:** `bin/magento smartsearch:sync:full` (`Console/Command/FullSyncCommand.php:62`)

| Option | Default | Meaning |
|---|---|---|
| `--force` | off | Ignore the delta cursor — send everything. Sets `$lastSyncAt = null` (`:96`). |
| `--store=N` | `0` | `0` = every store with an API key. |
| `--page=P` | `1` | Resume the upsert loop from page P after an interruption. |

`BATCH_SIZE = 300` (`:24`) for every collection and every chunk. `RECONCILE_MAX_DELETE_COUNT = 10000` (`:36`) caps reconciliation deletions.

**Per store, `syncStore()` (`:217`) runs five passes**, after precomputing four exclusion sets:

```
Precompute:  getOrphanedChildIds()                             [DisabledParentResolver:39]
             getOverpricedConfigurableFamilyIds(90000)          [:151]
             getConfigurableParentIdsWithNoInStockChild()       [:191]
             getSupersededSkusAndIds()                          [DuplicateSkuResolver:72]

PASS 1  Upsert loop      → syncBatch(),  300/page, up to 4 attempts, sleep 10/20/30 s
                            lock re-checked every page → abort if the lock vanished
PASS 2  Disabled/OOS sweep                    → deleteBatch()
PASS 3  Fully-OOS configurable parents        → deleteBatch()
PASS 4  Overpriced families + superseded SKUs → deleteBatch()
PASS 5  reconcileDeletedProducts()  [:528]
          GET endpoint_url → the platform's product_ids
          diff against Magento's getAllIds()
          guards: Magento-empty / fetch-failed / >10000 → SKIP
                                              → deleteBatch()
```

**Paging is driven by the raw, pre-filter page count** (`:328`, `:405`) — `while (count($rawProducts) === self::BATCH_SIZE)`. A page that filters down to zero rows still counts as full, so the loop must continue or coverage stops early.

**Resume semantics.** `--page=P` seeds only the upsert loop. The cursor is advanced **only when `$completedCleanly && $startPage === 1`** (`:137-139`), because resuming from page N means pages 1..N-1 were never covered by this run.

**The two resolvers, and why they exist.** Both are batch-only — never used by the real-time path.

`Service/DisabledParentResolver.php` — all queries walk `catalog_product_super_link` (Magento's real parent-child table), so there is no naming-heuristic risk:

| Method | Returns | Why |
|---|---|---|
| `getOrphanedChildIds()` | children of **Disabled** configurable parents | a configurable child keeps its own independent `status`; disabling the parent does not disable children, so a still-Enabled child would sync standalone |
| `getOverpricedConfigurableFamilyIds()` | parents with any child over the cap, parents whose own price is over the cap, **plus every child of those parents** | the parent embeds all children, so suppressing one member alone is insufficient; pulling the parent while leaving siblings would orphan them |
| `getConfigurableParentIdsWithNoInStockChild()` | parents where **not one** child is in stock | a configurable parent's own stock row is virtually always `is_in_stock=1`, so no own-stock filter can ever see it as OOS |

⚠️ `getConfigurableParentIdsWithNoInStockChild($storeId)` **accepts `$storeId` and never uses it** (`:191-217`) — no store filter on either table. On a multi-store install it can mark a parent fully-OOS based on another store's stock.

`Service/DuplicateSkuResolver.php` handles re-import artefacts (`hair-oil`, `hair-oil-1` … `hair-oil-4` → only `-4` is current). Two guards, both from real incidents (`:19-31`):
- **Visibility guard** — only independently-visible products are candidates (`:87-89`). A configurable/bundle child is a real variant, not a stray copy, and must never mark its parent superseded.
- **Suffix length cap of 1–3 digits** — an 8–14-digit suffix is almost always a UPC/GTIN baked into an unrelated SKU sharing a brand prefix (e.g. `SFTO-743404201313`). Treating those as siblings "silently discarded every other real, distinct product sharing that prefix".

**Locking — `Service/SyncLockManager.php`.** An advisory **lock file**, not a DB lock: `fopen($path, 'x')` (`:38`) fails if the file exists, which is the atomicity guarantee.

| Item | Value |
|---|---|
| Lock file | `var/SmartSearch/full_sync.lock` |
| Result file | `var/SmartSearch/last_sync_result.json` |
| Lock payload | `{pid, source: admin\|command\|cron, started}` |
| `MAX_LOCK_AGE` | 14400 s (4 h) for `command`/`cron` |
| `ADMIN_TTL` | 60 s for the admin pre-lock |

`clearIfStale()` (`:182-211`) clears an `admin` lock older than 60 s, any lock older than 4 h, or any lock whose PID is dead (`posix_kill($pid, 0)`). `acquireOrTakeOver()` (`:52-73`) takes over an `admin` lock (keeping the original `started` timestamp) but **never steals a `command` or `cron` lock**.

The directory is created with mode **0775, not 0755**, and self-heals a wrong mode — because the admin controller runs as the web-server user, which is only a *group* member of the file owner; without the group-write bit, `acquire()` fails with an error indistinguishable from "already locked" (`:160-180`).

**Stopping is cooperative:** `StopSync` deletes the lock file; the running command checks `isLocked()` at five loop boundaries and sets `$aborted = true`. `$wasAborted` forces `$completedCleanly = false` (`:135`) — and the comment at `:131-134` is worth quoting: an aborted run can reach the end with `$totalFailed === 0`, and *"zero failures isn't the same as completion"*, so neither the cursor nor the chained image compression fires.

⚠️ **A stop-then-restart race:** `release()` is an unconditional `unlink` of whatever is at that path, and the aborting process calls it from both `register_shutdown_function` and `finally`. Click Stop and immediately start a new sync, and the old process's shutdown can delete the **new** run's lock file, which the new run then reads as an abort signal.

⚠️ **`StopSync` has no UI.** The controller works; nothing links to it. It is reachable only by a manual authenticated POST.

### 12.5 The payload

Built by `ProductSyncService::normalize()` (`:345-579`). Every field, as returned at `:557-578`:

`product_id`, `sku`, `type`, `name`, `price`, `special_price`, `in_stock`, `qty`, `visibility` (raw Magento int), `is_variant` (`visibility === 1`), `status`, `brand`, `manufacturer_brand`, `url_key`, `description` (`strip_tags`), `short_description` (`strip_tags`), `images: {main_image}`, `categories[]`, `attributes[{name,value}]`, `variants[]`.

Not sent: tier prices, catalog-rule prices, tax, currency, `final_price`, `msrp`, or the image gallery (only the single `image` attribute).

**Brand resolution is two fields and deliberately so** (`:358-376`):
- `brand` ← the Webkul marketplace seller's `shop_title`, via **direct SQL** rather than Webkul's helpers, because Webkul's own `getSellerCollectionObj()` resolves store scope from the ambient current-store context, which is unset or wrong under cron/queue (`:592-597`).
- `manufacturer_brand` ← raw EAV, trying `product_brand` then `manufacturer`, again via **raw SQL** because ~9,500 products in this catalogue carry a stale store-view-level `NULL` row that shadows a perfectly good `store_id=0` value (`:646-653`).
- If `brand` is empty it is overwritten with `manufacturer_brand` (`:374-376`).

**`resolveUrlKey()` checks `url_rewrite` FIRST and the `url_key` attribute only as a last resort** (`:924-986`). The 34-line docblock (`:889-923`) explains why with real numbers from an August 2026 investigation: the FlxPoint importer derives `url_key` from the product *name* with no uniqueness enforcement and writes `url_rewrite` rows with raw `INSERT … ON DUPLICATE KEY UPDATE`. Result: **12,299 products** had a `url_key` that disagreed with their own working rewrite, and **one slug was shared by 181 products** with only one holding a working route. The lookup also queries by `target_path` directly (`:964-976`), because FlxPoint's upsert repoints `target_path` without touching `entity_id`, so a row can permanently route to this product while still tagged with another's ID.

**Price cap: `Helper\Data::MAX_SYNC_PRICE = 90000.0`** — *"placeholder/test pricing (e.g. 99999) has repeatedly polluted the platform index and required manual cleanup"* (`Helper/Data.php:15-19`).

**Configurables:** `variants[]` is built from `getUsedProducts()` (enabled children only) with `variant_id`, `name`, `sku`, `price`, `special_price`, `in_stock`, `qty`, `image`. The parent's `qty` is forced to `0`, and `in_stock` is forced to `false` if there are no variants or no in-stock variant (`:517-526`) — which pulls the parent out of platform results without touching Magento's stock record.

⚠️ **Performance:** one `stockRegistry->getStockItem()` call **per child, inside a loop, inside a per-product `normalize()`, inside a 300-product batch** (`:503`). For a catalogue of many-variant configurables this is the dominant cost of a full sync.

### 12.6 ⚠️ The same situation produces three different outcomes

This is the single most important correctness table in the sync pipeline.

| Situation | Real-time `sync()` | CLI `FullSyncCommand` | Cron (dead) |
|---|---|---|---|
| Disabled / out of stock | **DELETE** (`:55-61`) | **DELETE** (`:383`) | **UPSERT** with real status (`Cron/ProductSync.php:191`) |
| Overpriced (>90000) | **SKIP**, never delete (`:70-76`) | **DELETE** family (`:459-487`) | Excluded, never deleted |
| Superseded duplicate SKU | not considered | Excluded **and** deleted | not considered |
| Orphaned child of disabled parent | not considered | Excluded | Excluded |
| Fully-OOS configurable parent | caught via the `variants` check | Excluded **and** deleted | not considered |
| Deleted from Magento | Observer deletes | Reconciliation deletes | not considered |

Both competing rationales are preserved in comments: `Cron/ProductSync.php:158-174` argues the platform's own search-time gate makes upserting sufficient and avoids a delete endpoint that used to time out; `FullSyncCommand.php:330-342` says that endpoint was batched in August 2026 so *"real deletion is safe again"*. **The cron file was never updated to match. Whoever revives the cron must reconcile this first.**

Also note: **a product that becomes overpriced stays on the platform** until someone runs the CLI, because the real-time path only skips.

### 12.7 The HTTP contract

One configured URL for the whole pipeline: `smart_search/general/endpoint_url`, expected to be the ingest path (e.g. `https://host/api/v1/ingest/products`). All three verbs hit it.

| Operation | Verb | Body | Timeouts |
|---|---|---|---|
| Single upsert | POST | `{batch_id, store_id, realtime:true, products:[doc]}` | connect 10 s / read **120 s** |
| Batch upsert | POST | `{batch_id, store_id, products:[…]}` (no `realtime`) | 10 s / **120 s** |
| Delete | DELETE | `{store_id, product_ids:["123"]}` | 10 s / 60 s |
| Reconciliation read | GET | — | 10 s / 60 s |

Headers on POST/DELETE: `Content-Type: application/json`, `X-Api-Key: <key>`, `X-Signature: sha256=<hash_hmac('sha256', $json, $apiKey)>`. ⚠️ **The API key doubles as the HMAC secret**, so the signature adds body integrity but zero authentication over `X-Api-Key`. A separate `smart_search/webhook/secret` config path exists but has **zero callers**. ⚠️ The reconciliation GET sends only `X-Api-Key` — no signature.

**`syncBatch()` return contract** (`:111-116`):

| Code | Meaning | Caller reaction |
|---|---|---|
| `>= 0` | products accepted | continue |
| `-1` | fatal: no URL, no key, or HTTP **401** | abort — do not retry |
| `-2` | product limit reached: HTTP **403** | stop sending, not a failure |
| `-3` | transient: **429**, any 5xx, `httpCode 0`, or a caught `Throwable` | retry |

**Only the CLI retries** — up to 4 attempts with 0/10/20/30 s backoff, in five identical copies of the same loop. Neither the real-time path nor the cron retries.

**`getPlatformProductIds()` returns `string[]|null`, and `null` must mean "could not verify — do nothing"** (`:274-279`), never "the platform has zero products" — conflating them would make a network hiccup look identical to "delete everything". ⚠️ It has **no paging**: it expects every product ID for the store in one response within 60 s. On a large catalogue that is the most likely reconciliation failure — and it fails *safely*.

⚠️ **Response bodies are logged but never parsed.** A `200 OK` carrying per-product rejections is counted as a full success.

### 12.8 Database tables

`Setup/UpgradeSchema.php` creates **nothing sync-related**. Its three tables are for the admin styling system (§14). Sync state lives in:

| State | Storage |
|---|---|
| Delta cursor | `core_config_data` → `smart_search/cron/last_sync_at` |
| Full-sync-requested flag | `core_config_data` → `smart_search/cron/full_sync_requested` (inert) |
| Run lock | `var/SmartSearch/full_sync.lock` |
| Last run result | `var/SmartSearch/last_sync_result.json` |
| Rate-limit counter | Magento **config cache**, key `smartsearch_observer_rate_<store>_<YmdHi>` |
| Queue messages | Magento core `queue*` tables (`Magento_MysqlMq`) — unused |

### 12.9 🧊 The entire message-queue layer is dead

Declared and correctly wired, but **nothing publishes to either topic**:

- Topics `falcosense.search.product.sync` and `falcosense.search.full_sync` (`etc/communication.xml`), both on the MySQL `db` connection (no RabbitMQ dependency).
- Consumers `smartSearchProductSync` (maxMessages 100) and `smartSearchFullSync` (1000) in `etc/queue_consumer.xml`.
- `WebhookPublisher::publish()` — **zero callers**. `FullSyncPublisher::publishBatch()` — one caller, `Service/FullSyncService.php:94`, which itself has **zero callers**.
- `Model/WebhookMessage.php` + `Api/Data/WebhookMessageInterface.php` are an unused DTO; both topics declare `request="string"` and both consumers hand-decode JSON.

Anyone running `bin/magento queue:consumers:start smartSearchFullSync` will watch it idle forever.

⚠️ **A probable latent defect:** `falcosense.search.full_sync` has **no binding in `etc/queue_topology.xml`** (only `…product.sync` does). For the MySQL queue driver, topology bindings are what bind topics to queues. Without one, messages published to that topic would likely never land in the queue. This could not be verified against Magento core (no `vendor/` in this repo), but it would surface the moment the queue path is revived.

🧊 Also dead: `Service/FullSyncService.php` (330 lines, its own duplicate `MAX_SYNC_PRICE` and a `BATCH_SIZE = 500` that disagrees with the live CLI's 300) — **and it still contains the PHP-8 OR-filter bug that was fixed everywhere else**. `FullSyncCommand.php:647-653` documents the fix:

> *"The previous two-separate-arrays form — `addFieldToFilter(['status','is_in_stock'], [['eq'=>…],['eq'=>…]])` — silently threw a TypeError under PHP 8 ('Cannot access offset of type string on string') every time this ran, meaning the disabled/OOS sweep has not actually been running at all."*

`Service/FullSyncService.php:249-255` still uses the broken form. Harmless while dead; a live landmine if wired up.

### 12.10 Other sync gotchas

- ⚠️ **The admin button runs `shell_exec`** with `env -i HOME=… PATH=…` (`Controller/Adminhtml/Sync/FullSync.php:63-72`). Inputs are `(int)`-cast and `escapeshellarg()`'d so it is not injectable — but `env -i` **wipes the environment**, so any Magento configuration supplied via environment variables (`MAGE_MODE`, container-injected DB credentials) is lost in the background process. On a containerised install this is a likely cause of "the button says it started but nothing happened".
- ⚠️ `FullSyncCommand` does **not** check `isEnabled()` — the CLI runs even when sync is disabled in admin. The admin controller does check it, at *default* scope, while passing `--store=N` from the request.
- ⚠️ A fatal `-1` (revoked API key) does **not** break the store loop — the run proceeds to the next store, while printing "Aborting."
- ⚠️ `resolveUrlKey()` falls back to store 1 when `$storeId` is 0 (`:927`), and sync calls default to store 0.
- ⚠️ `syncBatch()` applies **no eligibility checks of its own** — every filter is the caller's responsibility. A new caller that doesn't replicate `syncStore()`'s exclusion sets will push disabled and overpriced products straight to the platform. `FullSyncConsumer` only filters `status = ENABLED`.
- ⚠️ **`getPlatformStoreId()` remaps store IDs positionally** (§5.5). Adding or deleting a store view silently renumbers every other store's platform ID, invalidating every document already ingested. **Do not add or remove store views without coordinating with the platform team.**
- ⚠️ `DuplicateSkuResolver::getSupersededSkusAndIds()` loads the **entire enabled catalogue** into PHP memory with no paging (`:74-93`) — a large single allocation on a 150k-product catalogue, once per store per CLI run.
- 🟠 Vestigial `$count = $attempt = 0;` immediately followed by `$count = -3;` in all five retry loops. `delete_http()` is snake_case in a camelCase codebase. `etc/queue_consumer.xml:12` claims "batches of 500 product IDs queued by the admin button" — the button queues nothing and the live batch size is 300.
- 🟠 **Four names for one thing.** Log tag `[SmartSearch]`, module `FalcoSense_Search`, config section `smart_search`, admin tab `Ahy`, ACL title "Smart Search Configuration". When grepping, try all of `SmartSearch`, `smart_search`, `smartsearch`, `FalcoSense`.

---

## 13. Behavioural analytics, visitor identity and geo-consent

### 13.1 WHAT and WHY

A relevance engine improves the more it knows about what shoppers actually do. This subsystem is the telemetry uplink: a Magento event fires, a small observer catches it, and a service POSTs a compact JSON record to `/api/v1/events`. The platform uses that stream to re-rank results, power trending/slider content, and build merchant analytics.

**Event catalogue.** Server-side, via `Service/CustomerEventService.php`:

| `event_type` | Method | Line | Purpose |
|---|---|---|---|
| `customer_login` | `trackLogin()` | `:44` | **Identity stitching** — binds the anonymous `visitor_id` to a known `customer_id` |
| `customer_register` | `trackRegister()` | `:55` | same, for new accounts |
| `customer_logout` | `trackLogout()` | `:63` | closes the session so later events aren't misattributed |
| `product_view` | `trackProductView()` | `:71` | highest-volume interest signal |
| `add_to_cart` | `trackAddToCart()` | `:92` | strong intent, weighted far above a view |
| `remove_from_cart` | `trackRemoveFromCart()` | `:115` | negative signal / abandonment |
| `wishlist_add` | `trackWishlistAdd()` | `:138` | medium intent |
| `purchase` | `trackPurchase()` | `:173` | conversion ground truth + revenue |

Client-side, from `search/results.phtml`: `search_click` (with grid `position` and `query` — the input to click-through-rate-per-rank, i.e. learning-to-rank), plus duplicate `add_to_cart` and `wishlist_add` beacons, plus a search-impression POST to `/api/v1/analytics/search` carrying `{query, result_count, response_time_ms, page}`.

⚠️ **`search_click` is produced only by the full search results page.** The category PLP, the header overlay, the sliders and the collection grid have **no click tracking at all**.

### 13.2 Observer inventory

`etc/frontend/events.xml` (frontend area):

| Magento event | Observer | Payload |
|---|---|---|
| `customer_login` | `CustomerLoginObserver` | `customer_login` + `customer_id` |
| `customer_register_success` | `CustomerRegisterObserver` | `customer_register` + `customer_id` |
| `catalog_controller_product_view` | `ProductViewObserver` | `product_view` + product fields |
| `checkout_cart_product_add_after` | `AddToCartObserver` | `add_to_cart` + product fields + `quantity` |
| `sales_quote_remove_item` | `RemoveFromCartObserver` | `remove_from_cart` + fields + `quantity` |
| `wishlist_add_product` | `WishlistAddObserver` | `wishlist_add` + fields |
| `customer_logout` | `CustomerLogoutObserver` | `customer_logout` + `customer_id` |

`etc/events.xml` (**global** area): `sales_order_place_after` → `PurchaseObserver`, sending `purchase` with `order_id`, `order_number`, `grand_total`, `currency`, and an `items[]` array. Global scope is deliberate (`etc/events.xml:17`): *"Fires in global scope after order is placed (Hyva/Magewire checkout runs outside frontend scope)"*. Correct for Hyvä checkout, with side effects — see §13.6.

🧊 **Two observers are registered nowhere:** `Observer\SearchQueryObserver` (99 fully-implemented lines; superseded by the client-side `trackSearch()`, which can report real `result_count`/`response_time_ms` instead of its hard-coded zeros) and `Observer\LogApiKey` — see §17.

### 13.3 `CustomerEventService` — how it works

```php
private const COOKIE_NAME = '_ahy_vid';   // :28
private const TIMEOUT_SEC = 2;            // :29
```

The constructor takes only `Helper\Data` and `LoggerInterface` — no session, no store manager, no cookie manager. Callers pass customer and store IDs in; everything else comes from PHP superglobals. All eight public methods are `void` and end in `send($payload, $storeId)`.

`send()` (`:208-289`): log → `isEnabled()` kill switch → resolve URL + API key → log the key prefix ⚠️ → bail if unconfigured → remap store ID → **merge the ambient context** → encode → raw cURL POST → log the outcome.

The ambient context (`:230-239`) is the part that matters:

```php
$payload = array_merge($eventData, [
    'store_id'   => $platformStoreId,
    'visitor_id' => $_COOKIE[self::COOKIE_NAME] ?? null,
    'session_id' => session_id() ?: null,
    'ip_address' => $this->anonymizedIp(),
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'], 0, 512),
]);
```

**IP anonymisation** (`:301-328`) walks `HTTP_X_FORWARDED_FOR` → `HTTP_X_REAL_IP` → `REMOTE_ADDR`, then zeroes the last IPv4 octet or keeps only the first 48 bits of an IPv6 address. The docblock justifies this against German DPA (DSK) guidance. ⚠️ `X-Forwarded-For` is client-spoofable and taken on trust.

**🔴 It is fully synchronous, in-process, and blocking.** This is the most important operational fact in the subsystem, and it contrasts sharply with the product-sync pipeline in the same module, which has a whole (unused) RabbitMQ layer. The analytics path uses **none** of it: `curl_exec()` runs inline on the web request thread (`:256`) with **`CURLOPT_TIMEOUT = 2`** and no `CURLOPT_CONNECTTIMEOUT`. There is no queue, no cron deferral, no `fastcgi_finish_request()`, no batching (one observer firing = one HTTP request), no retry, no dead-letter, no local spool. **A failed event is lost forever.**

**Does a platform outage break the storefront?** No — but it makes it slow. Every observer wraps its body in `try/catch (\Throwable)` and logs without rethrowing, every observer short-circuits on `!isEnabled()`, and `send()` never throws. So:

| Outage shape | Effect |
|---|---|
| DNS blackhole / connection hang | **+2 s latency on every tracked interaction** — PDP views, add-to-cart, **and order placement** |
| Platform returning fast 500s | negligible latency, log noise only |
| Any outage | nothing user-visible breaks; **total data loss** for the window, no replay path |

**🔴 The purchase case deserves its own warning.** `sales_order_place_after` is dispatched by `Order::place()` **before the order is persisted** — which is exactly why the observer contains:

```php
// Entity ID may be null if order not yet persisted — use increment ID as primary key
$orderId = $order->getId() ?: $order->getIncrementId();   // PurchaseObserver.php:43-44
```

So a 2-second external HTTP call sits on the checkout critical path, in the window around order persistence, where Magento holds DB transactions and quote locks. Under a platform slowdown this lengthens the highest-value, least-forgiving transaction in the system and increases exposure to lock waits and gateway timeouts mid-checkout. **This is the top remediation candidate in the whole module.** The fix is to route `send()` through the RabbitMQ infrastructure the module already operates for product sync, or at minimum to defer it past `fastcgi_finish_request()`.

Secondary costs on the same path: `file_put_contents(…, FILE_APPEND | LOCK_EX)` on every event (`:205`) — an exclusive file lock per event, on a file that bypasses Monolog entirely and is therefore outside any logrotate rule. `PurchaseObserver` writes one line **per order item** plus five more, so a large order performs a dozen lock-acquiring appends. It also logs `--- PurchaseObserver::execute() FIRED ---` on **every order** with no debug flag guarding it (`:26`).

### 13.4 Visitor identity, cookies, and the geo pipeline

| Cookie | Value | Lifetime | Set by | Flags | Read by |
|---|---|---|---|---|---|
| `_ahy_vid` | UUID v4 | **365 days** | **JavaScript** (`visitor_cookie.phtml:20-44`) | `path=/; SameSite=Lax`. Not `Secure`, not `HttpOnly` | PHP (`CustomerEventService.php:232`) |
| `ahy_geo_state` | region name | 30 days | **JavaScript** (`geo-consent.phtml:21-27`) | same | **nothing in this module** |
| `ahy_geo_tried_v2` | `"1"` | session | `sessionStorage`, not a cookie | — | `geo-consent.phtml:37-39` |

**Everything is set from JavaScript.** There is not a single `CookieManagerInterface` reference in the module, and no `cookies.xml`. This is a deliberate FPC-friendly choice — a PHP-set cookie would be baked into the cached page — but it means the cookies are invisible to Magento's cookie-restriction machinery and to a CMP's automated cookie scan.

`visitor_cookie.phtml`'s docblock is good KT material:

> *`_ahy_vid` : persistent UUID visitor ID (1 year), used to correlate: anonymous browsing → login → purchases; search queries → PDP views → add-to-cart. SameSite=Lax : cookie sent on top-level navigation, blocked on cross-site POSTs. NOT HttpOnly : must be readable by the tracking JS. First-party : set on your own domain, not a third-party tracker. crypto.getRandomValues : cryptographically secure UUID v4 (not Math.random).*

⚠️ `crypto.getRandomValues` requires a secure context; on plain HTTP `uuid4()` throws and no cookie is set (harmless but noisy on non-HTTPS dev boxes).

**Geo-consent** (`geo-consent.phtml`, 148 lines of vanilla JS) exists because detection works by calling a **third-party geolocation API from the visitor's browser** — `fetch('https://ipapi.co/json/')` (`:52`) — which discloses the visitor's raw IP to an unaffiliated third party. So it refuses to run until it can convince itself consent exists, via a five-tier CMP sniffer (`:73-110`): Magento Cookie Restriction Mode (`user_allowed_save_cookie`), OneTrust (`C0003`/`C0004`), CookieBot, CookieYes, and then — ⚠️ **no CMP found ⇒ `return true`, fail-open**.

**🔴 And the entire client-side geo pipeline is write-only dead weight in this module.** `ahy_geo_state` is written and **never read**. Its only readers live in the disabled sibling module (`code/Ahy/SmartSearchLuma/Block/Slider/Products.php:15`, which injects `CookieManagerInterface`). The FalcoSense rewrite dropped the reader: `Block\Slider\Products::getCustomerGeoState()` (`:65-121`) now resolves the region **purely from the logged-in customer's address book**, so **guests always get `''`** — i.e. for the vast majority of traffic, `geo_state` is always empty. Meanwhile the consent banner, the third-party geolocation call, and the cookie all still run.

Either restore the cookie read (recovering the guest-geo capability the rewrite lost) or delete `geo-consent.phtml` and its layout node. As it stands the module carries all of the compliance risk and none of the benefit.

⚠️ Two smaller geo bugs: the CookieBot listener is misspelled `CookybotOnAccept` (`:120`) so it never fires, and Magento's native banner is handled by a **5-attempt, 1-second poll** (`:123-130`) — take longer than ~5 s to click Accept and geolocation is skipped for that pageview.

**Also `ifconfig` asymmetry** (`default.xml:38-45`): the geo-consent block is gated on `smart_search/general/enabled`; the visitor-cookie block is **not**. Turning "Enable Sync" off stops all event submission but **keeps setting a 1-year tracking cookie on every page.**

### 13.5 The `getCustomerGeoCountry()` war story — preserved verbatim

This docblock (`Block/Search.php:191-211`) is the module's most valuable piece of institutional memory. Reproduced exactly:

```
    /**
     * Return the full country name for the logged-in customer (e.g. "United States").
     * Returns '' for guests or customers with no address.
     *
     * Geo boost rules are stored with a full country name (set via the admin's
     * country dropdown, e.g. "United States"), not the ISO code Magento addresses
     * store (e.g. "US") — GeoBoostService's matching is an exact string compare,
     * so without this conversion a rule scoped to a country would never match
     * even when geo_state resolves correctly, since geo_country would always be
     * sent as '' and the rule's non-blank geo_country requires an exact match.
     *
     * Uses the address object's own getCountryModel() (from
     * Magento\Customer\Model\Address\AbstractAddress) rather than injecting a
     * CountryFactory dependency — adding a new constructor argument here
     * previously required the auto-generated Interceptor class to be
     * regenerated, and a stale OPcache serving the old interceptor bytecode
     * broke the live search box until it self-resolved, with no way to force
     * a PHP-FPM restart. This avoids touching the constructor at all.
     *
     * Address priority: default billing → default shipping → first address.
     */
```

**The lesson for a fresher, spelled out.** In Magento 2, most classes fetched from the object manager are served through an auto-generated `Interceptor` subclass whose constructor signature mirrors the original. Change a constructor and that generated file must be regenerated (`setup:di:compile`) **and** the old bytecode must leave OPcache. On this environment there was no way to restart PHP-FPM, so a stale interceptor kept being served and the **live search box broke** until OPcache expired on its own. The author's mitigation — *avoid touching the constructor at all* — is why the method reaches the country name through `$address->getCountryModel()` instead of injecting a factory.

**This is the single most important deployment gotcha in the module** and it is repeated in §19.

⚠️ **And the fix is not plugged in.** A repo-wide grep for `getCustomerGeoCountry` and `geo_country` matches only its own definition. `search/results.phtml:715` sends `geo_state` and nothing else. The bug the story describes is fixed in code but never wired up, so the `geo_country` parameter the platform reportedly needs is still never sent.

### 13.6 Duplicate and misattributed events

- **`add_to_cart` fires twice** from the search results page: `addSimpleToCart()` POSTs to `checkout/cart/add`, which dispatches `checkout_cart_product_add_after` → `AddToCartObserver` → one POST; then `search/results.phtml:1037` calls `trackEvent()` → a **second** POST via `sendBeacon`. Two records per action, with different identity fields (the server one has `visitor_id`+`session_id`+`customer_id`; the beacon has `search_token`+`query` and **no** `visitor_id`, because `sendBeacon` is cross-origin and can't set headers). **Add-to-cart conversion from search is double-counted** unless the platform dedupes.
- **`wishlist_add` fires twice**, identically.
- **`customer_login` + `customer_register` both fire on registration** — Magento's `CreatePost` dispatches `customer_register_success` *and* logs the customer in.
- ⚠️ **`PurchaseObserver` uses the wrong store ID** — `$this->storeManager->getStore()->getId()` (`:45`) instead of `$order->getStoreId()`. Because the observer is **global**, it also runs for admin-created and REST/GraphQL orders, where the current store is the admin store (id 0). `getPlatformStoreId(0)` can't find store 0 and falls back to `1`. So back-office and API orders on a multi-store install are attributed to the wrong platform store — and the ambient context then attaches the **admin user's** session ID, IP and user-agent to the event, contaminating behavioural analytics with staff activity.
- ⚠️ **`sales_quote_remove_item` is noisier than "user removed an item"** — Magento dispatches it for quote merges on login, quote cleanup, reorder flows, and item replacement during qty edits. Treat `remove_from_cart` as a weak signal.
- ⚠️ **`customer_register_success` covers only the frontend controller path** — GraphQL, REST, admin creation, and a Magewire/headless "create account" step produce no `customer_register` event.
- ⚠️ **`ProductViewObserver`'s docblock over-promises**: it claims *"CustomerSession is injected as a proxy so it doesn't force session start on non-PDP pages"* (`:19`). There is **no proxy configured** — the constructor takes `Magento\Customer\Model\Session` directly and `di.xml` has no entry for this class.
- ⚠️ **`isEnabled()` scope mismatch** in `send()`: the kill switch is read with no store argument (`:213`) while the URL and key are read *with* `$storeId` (`:218-219`). On a multi-store install with per-store overrides — particularly from the globally-registered `PurchaseObserver` — the switch and the credentials can come from different scopes.

---

## 14. Admin configuration and the theming/style system

### 14.1 Where the admin goes

**Stores → Configuration → Ahy → FalcoSense**

| Level | Internal ID | Label | Declared at |
|---|---|---|---|
| Tab | `ahy` | Ahy | `etc/adminhtml/system.xml:6-8` |
| Section | **`smart_search`** | **FalcoSense** | `:10-14` |
| Group | `general` | General Settings | `:16` |
| Group | `add_to_cart_button` | Product Card: Add to Cart Button | `:71` |
| Group | `product_card` | Product Card | `:121` |
| Group | `tools` | Tools | `:156` |

⚠️ **The section ID is the legacy name `smart_search` while the label reads "FalcoSense".** Every config path in code therefore begins `smart_search/…`, never `falcosense/…`. This is the single most common source of confusion on this module. Magento also builds each field's HTML id as `{section}_{group}_{field}` — e.g. `smart_search_product_card_corner_radius` — which is what the live preview's JS targets.

The full field inventory is in §5.5 (functional fields) plus the style fields below. Two non-fields worth naming: `version_info` (`Block\Adminhtml\System\Config\VersionInfo`) renders **only a logo, no version number**, from a URL hard-coded to `https://dev2.everest.com/media/wysiwyg/falcosense-logo.png` — a broken image on any other environment, production included; and `card_preview` (§14.4).

### 14.2 🔴 The style system uses a SECOND config store

This is the most surprising piece of architecture in the module.

*Ordinary settings* (API key, endpoint, toggles) live in `core_config_data` and are read through `ScopeConfigInterface`.

*Visual settings* (roundness, borders, shadows, spacing, columns, button style, labels, icon) are **also** written to `core_config_data` — **but that copy is never read on the storefront.** The copy that matters is mirrored into three module-owned tables, and the storefront reads only those.

```
Admin form Save
      │
      ├──► core_config_data                    ← write-only shadow copy, never read on the storefront
      │
      └──► Model\Config\Backend\StyleValue::afterSave()
                    │ MirrorsStyleValueTrait::mirrorStyleValue()
                    ▼
           falcosense_style_value  (attribute_id, scope, scope_id, value)   ← THE SOURCE OF TRUTH
                    ▲
                    │ Model\StyleConfig\Reader  (store → website → default → attribute.default_value)
                    │
       ┌────────────┴─────────────┐
       │                          │
 Block\ProductCard          ViewModel\StyleConfig
 (module's own templates)   (templates owned by other modules, e.g. Webkul seller profile)
       │
       ▼
 global-style-vars.phtml  →  <style> :root { --ahy-card-radius … } </style>
       │
       ▼
 every card/grid references var(--ahy-card-*)
```

**The three tables** (`Setup/UpgradeSchema.php`, each guarded by `isTableExists()`):

| Table | Purpose | Key columns |
|---|---|---|
| `falcosense_style_component` | A styleable UI component. Its `code` **must equal a system.xml group id**. | `component_id`, `code` (unique), `label` |
| `falcosense_style_attribute` | One styleable property. Its `code` **must equal a system.xml field id**. | `attribute_id`, `component_id` (FK CASCADE), `code`, `input_type`, `default_value`, `sort_order`; unique on `(component_id, code)` |
| `falcosense_style_value` | The per-scope saved value. | `value_id`, `attribute_id` (FK CASCADE), `scope` (`default`/`websites`/`stores`), `scope_id`, `value`, `updated_at`; unique on `(attribute_id, scope, scope_id)` |

**The coupling contract, enforced only at runtime.** `MirrorsStyleValueTrait::resolveAttribute()` (`:17-41`) splits the config path — `[, $componentCode, $attributeCode] = explode('/', $path)` — and looks the pair up. No match ⇒ it throws `LocalizedException('No style attribute registered for "%1/%2".')`. **So renaming a group or field id in `system.xml` without a matching `UpgradeData` row makes that field unsavable**, and because the exception aborts the whole section save, every other field the merchant edited in that submit is rolled back with it.

**Validation lives in the database, not in system.xml.** `StyleValue::beforeSave()` (`:32-46`) branches on the DB's `input_type`: `slider` ⇒ `^\d{1,4}$`, `text` ⇒ max 50 chars, and **`select` / `image` get no server-side validation at all**. So `border_radius` is declared `type="text"` in system.xml but validated as a slider. There is no client-side `<validate>` on any style field.

**The emitted CSS** (`view/frontend/templates/global-style-vars.phtml`, in `before.body.end` on **every** page via `default.xml:33-36`):

| Emitted | From | Shape | Line |
|---|---|---|---|
| `--ahy-card-radius` | `product_card/corner_radius` | `Npx` | `:27` |
| `--ahy-card-border` | `product_card/border_shadow_style` | `1px solid #e5e7eb` or `none` | `:28` |
| `--ahy-card-shadow` | same field | a two-layer shadow or `none` | `:29` |
| `--ahy-grid-gap` | `product_card/card_spacing` | `Npx` | `:30` |
| `.ahy-product-grid { grid-template-columns: repeat(N, …) }` inside `@media (min-width:1280px)` — **a rule, not a variable** | `product_card/columns_per_row` | N ∈ 2/3/4 | `:32-34` |

All values are `(int)`-cast or `in_array`-mapped, so nothing merchant-supplied reaches the CSS text verbatim — no injection surface.

⚠️ **The `add_to_cart_button` component uses a completely different delivery mechanism**: its five values are read per-template and PHP-interpolated into attributes and Tailwind class strings, independently, in **eight** templates — each re-deriving the same fallbacks (`'9999'`, `'outline'`, `'Add'`, `'Options'`), and the slider using its own literal hex colours and *lowercase* label fallbacks. Adding a new button property means editing eight files. Moving it onto the variable mechanism is the obvious consolidation.

### 14.3 WHY this design exists

**Non-technical:** how rounded the cards are, whether they have a border or a shadow, how much air is between them, how many fit per row, and what the buy button says — those are things a merchant or designer wants to change on a Tuesday afternoon, look at, and change again. Without this system each change is a code change: edit a stylesheet, commit, review, deploy, rebuild static assets. Hours to days per tweak, a developer needed every time, and impossible to differ between two store views. With this system it is a dropdown and a Save.

**The specific engineering problems solved:**

1. **Deployment cost.** Hyvä styling is *compiled* — Tailwind classes are extracted and a bundle is built, then `setup:static-content:deploy` publishes it. A one-pixel spacing change would require that whole pipeline. Emitting values as CSS custom properties at render time removes it entirely.
2. **Per-scope theming.** A stylesheet is per-theme; `falcosense_style_value` is per-scope. Store view A can have 4 rounded columns while B has 3 square ones, from the same code and theme.
3. **One definition, many call sites.** Nine separate templates draw a card or a card grid. Before the variables, a chrome change had to be applied nine times consistently.
4. **Compatibility with client-side rendering** — the decisive reason. Most of these cards are **not rendered by PHP at all**; they are Alpine `x-for` templates hydrated from an API response. You cannot interpolate a PHP value into a card PHP never renders. A CSS variable on `:root` styles all of them, however many the JS creates.
5. **Why extra tables rather than just `core_config_data`?** `core_config_data` is a flat key/value store with no notion of which component a value belongs to, no per-attribute type or default metadata, and no room for a future admin UI over the same data. The component→attribute→value triple gives the style system a typed schema (`input_type` drives validation, `default_value` drives the fallback). The declared-but-unused input types in the column comment (`color`, `boolean`, `json`) show the intent: this is the skeleton of a general theme editor, of which `system.xml` is currently just a hand-written first front end.

**The honest cost:** two sources of truth for the same value, a name-coupling contract enforced only by a runtime exception, an uncached per-render SQL path, and no invalidation of the cache that actually holds the rendered CSS.

### 14.4 The admin card preview

`Block/Adminhtml/System/Config/CardPreview.php` **overrides `render()` wholesale** (`:20`), returning raw `<tr colspan="4">` markup — so no label cell, no scope/inherit checkbox, no comment. It draws two mock cards (simple + configurable, 220×478 px) and an IIFE that binds `input`/`change` listeners to **six sibling admin inputs by DOM id** and mirrors their live values into the mock. It reads the *DOM*, not saved config, so the preview reacts before Save. It reaches across group boundaries — the preview lives in `add_to_cart_button` but reads two `product_card` fields.

**How it drifts out of sync with the real card:**

1. **Duplicated fallbacks** — the JS hard-codes `'9999'`, `'Add'`, `'Options'`, `'0'`, `'none'`. The storefront's fallbacks are the DB `default_value` column plus per-template literals. Change a seed default and the preview silently keeps the old one.
2. **Duplicated CSS literals** — `1px solid #e5e7eb` and the shadow string appear in `CardPreview.php:108-109` **and** `global-style-vars.phtml:19,22`. Two copies, no shared constant.
3. **Duplicated brand colours** — `#0d2f47`/`#ffffff` here vs the Tailwind tokens `bg-ahy-blue`/`!text-ahy-blue` on the storefront. Retune the theme token and the preview lies.
4. **Duplicated layout** — 220 px width, 478 px height, 244 px image, font sizes — all invented here to *look like* the real card, whose geometry comes from Tailwind classes elsewhere. Any card redesign leaves the preview showing the old one.
5. **Un-previewed fields** — `card_spacing` and `columns_per_row` aren't wired in at all (single cards, no grid). The icon renders at 14 px here vs `w-4 h-4` (16 px) on the storefront.
6. **Blind to inheritance** — because it reads DOM values, a field with "Use Default" ticked previews the inherited `core_config_data` value, which given gotcha 1 below can legitimately differ from the `falcosense_style_value` one.

There is no test asserting preview↔storefront parity.

### 14.5 Scope handling

Every group and field declares `showInDefault="1" showInWebsite="1" showInStore="1"` — including **Tools**, so a store-view-scoped admin can trigger a global full sync. (The disabled sibling restricted Tools to default scope only; FalcoSense dropped that.)

`core_config_data` fields behave normally. Three getters deliberately force **default scope only**, ignoring website/store overrides even though the fields are shown in those scopes: `getWebhookSecret()`, `getLastSyncAt()`, `isFullSyncRequested()`, and all three `image_compress` getters.

Style fields resolve through `Reader::getScopeCandidates()` (`:93-102`): `('stores', storeId)` → `('websites', websiteId)` → `('default', 0)` → `attribute.default_value`. Two divergences from real Magento behaviour:

- ⚠️ **The reader is always store-relative** — it calls `$this->storeManager->getStore()` with no way to pass a store ID. In any non-store context (admin, CLI, cron without store emulation) that resolves to the admin store and the chain collapses to `default`. Latent today, since nothing calls `Reader` outside a frontend request.

### 14.6 ACL

One resource only (`etc/acl.xml:4-19`):

```
Magento_Backend::admin → stores → stores_settings → Magento_Config::config → FalcoSense_Search::config
```

It gates the whole config section (`system.xml:14`) and all three admin sync controllers (`ADMIN_RESOURCE` constants).

⚠️ **Granularity is coarse:** one resource covers platform credentials, sync triggers **and** visual styling. There is no way to let a designer change card roundness without also granting the API key field and the Full Sync button. The natural split is a child resource declared on each `<group>`. Also: the ACL title still reads **"Smart Search Configuration"** while the section label reads "FalcoSense", so in Roles → Resources the admin sees the old product name.

### 14.7 🔴 Style gotchas, in severity order

**S1 — Reverting a style field to "Use Default" does not revert the storefront.** `StyleValue` implements `beforeSave()`/`afterSave()` but **no `afterDelete()`** (verified: only those two methods exist). Magento's "Use Default" checkbox routes the field into the delete transaction, removing the `core_config_data` row — but the `falcosense_style_value` row **survives**, and the Reader reads only that table. Result: the admin form shows the inherited value, the storefront keeps rendering the deleted override, **forever, with no way to fix it from the UI**. Today's workaround is a manual `DELETE FROM falcosense_style_value WHERE attribute_id=? AND scope=? AND scope_id=?` plus an FPC flush. The proper fix is an `afterDelete()` on both backend models.

**S2 — Saving a style field does NOT invalidate any cache.** `StyleValue::afterSave()` (`:48-61`) **never calls `parent::afterSave()`**, so core's `cacheTypeList->invalidate(Config::TYPE_IDENTIFIER)` is skipped — and so are the `model_save_after` / `clean_cache_by_tags` / `config_data_save_after` events. Since the value is read by raw SQL at render time, the Configuration cache is irrelevant anyway — but **the rendered HTML, including the entire `<style>` block, is what FPC stores.** Without a manual Page Cache purge, already-cached pages keep the old CSS indefinitely, and **no admin banner appears to suggest a flush**. This is the number-one support question a new developer will field: *"I changed the setting, saved, and nothing happened."* (`StyleImage::afterSave()` does call `parent::`, so the Icon field is the one exception.)

**S3 — A saved-blank field at a narrow scope blocks inheritance.** `Reader::getValue()` accepts an empty string as a set value (`:57` tests only `!== false && !== null`). Since Magento always posts a value for `text`/`select` inputs, saving the section at store-view scope with a blank "Corner Roundness" writes `''` at `('stores', N)`, shadowing the website and default values. `(int)''` is `0`, so the storefront silently gets radius 0 and **gap 0** — a visible regression for `card_spacing`. `columns_per_row` is the only field defended against this (`global-style-vars.phtml:14-16`).

**S4 — "Columns Per Row" is broken on the search page, for two compounding reasons.** (a) `search/results.phtml:199` — the SSR grid — carries `style="gap:var(--ahy-grid-gap)"` but **not** the `ahy-product-grid` class, so it renders at the hard-coded `xl:grid-cols-3`; the category equivalent (`category/results.phtml:83`) *does* carry the class, so this is an inconsistency, not a decision. (b) The hydrated Alpine grid at `:512` *does* carry the class, but **it is inside the shadow root**, and `global-style-vars.phtml`'s light-DOM `<style>` cannot reach in (§8.7). So the setting has no effect on the search page at all, in either state, while `system.xml:149` explicitly promises it *"Applies to the search results, category, and seller profile grids"*.

**S5 — The `global-style-vars.phtml` docblock is now factually wrong, and there is a real FOUC window.** Its comment asserts it is *"safe to place near the end of body: every product card on this site is rendered client-side by Alpine (x-for) after the full page — including this block — has already loaded, so there is no flash of unstyled content."* That premise no longer holds: `search/results.phtml:50` and `category/results.phtml:43` build **server-rendered** cards that appear in the HTML *before* the `<style>` block in `before.body.end`. Until the browser parses it, `border-radius: var(--ahy-card-radius)` and `gap: var(--ahy-grid-gap)` are invalid-at-computed-value-time, so SSR cards paint with 0 radius, no border/shadow and `gap: normal`. Compounding it, **not one `var()` call site anywhere supplies a fallback** — `var(--ahy-grid-gap, 2px)` would make the whole system fail soft. Move the block to `<head>`, or add fallbacks.

**S6 — `product_card` styling silently skips several real card renderers**, despite the comment claiming it "Applies to every product card": `slider/smart-slider.phtml` never references `--ahy-card-*`; `slider/collection.phtml` hard-codes `border-radius: 9999px`; `collection/results.phtml` hard-codes `!rounded-full`; and the loading skeletons omit the variables, so skeletons don't match the cards that replace them.

**S7 — The API key is stored in plaintext.** `type="password"` only renders an obscured input; it does **not** encrypt. Encryption needs `backend_model="Magento\Config\Model\Config\Backend\Encrypted"` (conventionally with `type="obscure"`), which this field lacks. The key sits readable in `core_config_data`, in DB dumps, and in `bin/magento config:show` output.

**S8 — SVG is an accepted icon upload.** `StyleImage::_getAllowedExtensions()` adds `svg`. Core validates only extension and size — no content or SVG sanitisation. The icon is consumed via `<img src>`, where scripts inside an SVG do not execute, so the render path is not itself an XSS vector; the residual risk is that the file is directly reachable at its media URL.

**S9 — The media path is declared twice**: `falcosense/icons` in `system.xml:93-94` and again as `Reader::IMAGE_MEDIA_PATH` (`:19`). Change one without the other and every icon 404s silently.

**S10 — `Reader` is uncached and query-chatty.** Every `getValue()` is 1 attribute query + up to 3 value queries, with no memoisation and no cache layer. A page rendering the header cards (5 calls × 2 templates) plus `global-style-vars.phtml` (4 calls) issues ~14 calls ⇒ up to ~50 queries per uncached render. FPC hides this in production; it is visible in dev and on every FPC miss. An in-instance array cache keyed on `component/attribute` is a two-line fix.

**S11 — `label_text`'s default depends on install history.** `UpgradeData` seeded `'Add'` at 1.0.2 then corrected it to `''` at 1.0.3, because *"a shared default was overwriting the Options button's text site-wide"*. An environment that jumped from pre-1.0.1 to 1.0.5 runs both in order and ends at `''`; one stuck at 1.0.2 has `'Add'`. Note also that `etc/config.xml` contains **no defaults for any style field** — the only defaults are the DB `default_value` column. Don't look in `config.xml` for them.

**S12 — Inline `<style>`/`<script>` with no CSP nonce.** `global-style-vars.phtml` emits inline `<style>`; `CardPreview`/`SyncAllButton`/`SliderInfo` emit inline `<script>` and `onclick`. **No file in the module or the theme uses a CSP nonce** (zero grep hits for `csp_nonce`). The theme ships **two competing CSP `<meta>` tags** — one at `Magento_Theme/layout/default.xml:3-9` **without** `'unsafe-inline'` and with an `img-src` that excludes `static.everest.com`, and one at `default_head_blocks.xml:5` **with** `'unsafe-inline'`. Browsers enforce the **intersection** of multiple policies, which would block the inline handlers, the fallback logo, and the Swiper CDN script. Since the site works, one must be inert in practice (likely overridden by a real header) — but if CSP is ever moved to enforcing, the entire style-variable system, the admin preview and the sliders go dark silently.

---

## 15. Images and the compression subsystem

### 15.1 The four competing image-URL strategies

| Consumer | Host actually used |
|---|---|
| `Helper/ProductImage.php` 🧊 (dead) | `getBaseUrl(URL_TYPE_MEDIA)` → **rewritten to `www.everest.com`** by `Ahy\ThemeCustomization\Plugin\MediaBaseUrlPlugin` |
| `Model/Cart/ImageProvider.php` (live, global preference) | the **current origin** — `CDN_BASE = '/media/catalog/product'` is a root-relative path, not a CDN |
| Slider templates + search's Alpine/PHP | the current origin, relative `/media/falcosense/800x800/…` |
| Category + collection Alpine | hard-coded `https://static.everest.com/media/falcosense/800x800` |
| `etc/env.php` `base_media_url` = `https://d1sq8cqyuyotg2.cloudfront.net/media/` | **used by none of the above** |

**The environment fact that explains most of the confusion:** `etc/env.php` sets `base_url` to `https://dev2.everest.com/` and `base_media_url` to a CloudFront host — but `Ahy\ThemeCustomization\Plugin\MediaBaseUrlPlugin` has an `afterGetBaseUrl` plugin that **rewrites the host of every `URL_TYPE_MEDIA` base URL to `www.everest.com`** so dev/staging can reuse production's shared image library. `Model/StyleConfig/Reader.php:76-83` documents this and deliberately sidesteps it (using `URL_TYPE_WEB` + `Filesystem::getUri(MEDIA)`) so a locally-uploaded button icon stays on the host it was uploaded to.

**The shared algorithm** in all four template-level builders: `filename = basename(path)`; `c1 = filename[0]`, `c2 = filename[1] ?? c1`; result `{base}/{c1}/{c2}/{filename}`. Pass-through if the input already contains `falcosense/800x800`.

**The fallback image** is hard-coded in five live places as `https://static.everest.com/media/.thumbswysiwyg/everest-logo_2_.png`, always rendered at `opacity: 0.45` so the greyed logo reads as "no image" rather than as branding. (The Hyvä checkout template uses a *different* fallback file — two "no image" logos on one site.)

⚠️ **`Model/Cart/ImageProvider` has three problems** beyond the wrong host: it serves the **full-size original at 78×78** (a 3 MB photo downloaded to render a thumbnail), it bypasses both the configured CloudFront URL and the plugin rewrite so **minicart thumbnails 404 on dev2**, and it calls `parent::getImages($cartId)` **inside the per-item loop** (`:41`), re-fetching and re-rendering the entire cart on each miss — O(n²) for a cart with several image-less items.

🧊 **`Helper/ProductImage.php` is entirely dead** — zero references anywhere. Note there is a **separate, live** `code/Ahy/ThemeCustomization/Helper/ProductImage.php`; easy to confuse.

### 15.2 What the compression subsystem does

For each **enabled** product it takes the single main `image` attribute (never the gallery), decodes it, shrinks it to fit inside an 800×800 box **if larger**, re-encodes at high quality, and writes to a **brand-new file** at `pub/media/falcosense/800x800/{c1}/{c2}/{filename}`. Originals under `pub/media/catalog/product/` are **never** written, moved, renamed or deleted.

**The engine** (`Service/ImageCompressionEngine.php`): `JPEG_QUALITY = 92`, `PNG_LEVEL = 6`, `WEBP_QUALITY = 92`, `MAX_DIMENSION = 800`. **GD only** — *"no Imagick on this server"* (`:9-10`), which dictated the whole design.

Notable behaviours:
- **Format comes from `getimagesize()`, not the file extension** (`:45-46`) — a PNG mis-saved as `.jpg` still round-trips.
- Writes to `$destPath . '.tmp-' . getmypid()` then **`rename()`** (`:95`) — atomic; the destination is never half-written.
- **Never-a-regression guard** (`:89-92`): if the re-encoded file is `>=` the source size, discard it and copy the original bytes instead — because GD promotes palette PNGs to truecolor on decode and a fixed q92 can exceed the source's own quality.
- `resizeToFit()` (`:161-190`) returns `$src` untouched when both axes are already ≤ 800 — **never upscales, never crops, never stretches**. Alpha preserved for PNG/WebP; JPEG gets a white background.
- ⚠️ **An ownership footgun documented at `:157-159`:** it destroys `$src` when it resizes but returns `$src` itself when it doesn't. Callers must not double-free. All three current callers are correct.
- Verbatim `@copy()` fallback for undecodable/unsupported input, so no product ends up with *no* image in the compressed tree.

**The orchestrator** (`Service/ProductImageCompressionService.php`): SQL over `catalog_product_entity` ⨝ `eav_attribute` (`image`) ⨝ `catalog_product_entity_varchar`, INNER JOIN on `status = ENABLED`, `ORDER BY updated_at DESC`, `LIMIT $limit`. The **skip test** is `is_file($dest) && filemtime($dest) >= filemtime($src)` — so swapping a product's image file recompresses it automatically, and leaving it alone costs one `stat()`.

**🔴 The cursor-safety invariant** (`:43-52`), honoured by all three callers: because rows come back **newest-first**, a run cut short by `$limit` only saw the *top* of the backlog. Advancing the cursor to that top value would **permanently strand every older unprocessed row**. So callers must not persist the cursor when `truncated === true`.

⚠️ `800x800` is a **hard-coded bucket label, not the actual output dimensions** — a 300×200 source lands in the `800x800` folder at 300×200.

### 15.3 Triggers

| Trigger | Command / schedule | Limit | Notes |
|---|---|---|---|
| **CLI** | `bin/magento smartsearch:image:compress [--full] [--limit=N]` | default **500** | `--full` ignores the cursor **and suppresses cursor persistence entirely**. Lock `falcosense_search_image_compress`, TTL 3600 s; already-locked ⇒ exit 1. Exit 1 if any failures. |
| **Cron** | 🔴 **NONE — `etc/crontab.xml` is empty** | — | `Cron/ImageCompress.php` is unreachable. It is the **only** caller of `smart_search/image_compress/enabled` and of `batch_size`, so both config values currently control nothing. |
| **Chained from full sync** | after a **fully clean, unaborted** `smartsearch:sync:full` | hard-coded **500** (not `batch_size`) | Shares the same lock. Wrapped in `try/catch`; a crash here never changes the sync command's exit code. |

**This chained path is currently the only automatic trigger in the system**, and it is only as frequent as someone runs the full sync.

### 15.4 Is it safe to run on production?

**Yes, with three caveats.** The design is genuinely conservative: additive-only, never touches catalog originals, holds a proper distributed lock, idempotent via mtime, degrades to a verbatim copy rather than producing nothing. The only files it can overwrite are files in its own `pub/media/falcosense/` tree.

```bash
# 1. Check disk headroom — worst case ≈ the size of catalog/product's main images
du -sh pub/media/catalog/product

# 2. Small probe, watch both logs
bin/magento smartsearch:image:compress --limit=50
tail -n 50 var/log/smartsearch-image-compress.log
tail -n 50 var/log/smartsearch-image-compress-error.log

# 3. Scale up off-peak, in chunks rather than one giant run
bin/magento smartsearch:image:compress --limit=2000
```

**Caveat 1 — CPU.** GD decode + resample + re-encode is single-threaded and CPU-bound. A few thousand images will saturate a core for minutes. Run off-peak; prefer several `--limit=2000` runs over one `--limit=200000`.

**Caveat 2 — 🔴 memory, and a stuck lock.** `imagecreatefromjpeg()` allocates roughly `w × h × 4` bytes; a 10000×10000 JPEG needs ~400 MB. Exceeding `memory_limit` is a PHP **fatal error, not a `Throwable`** — it kills the process, and **the lock is left held for its full 3600-second TTL**, blocking every subsequent run for an hour. There is no dimension pre-check before decoding, even though `getimagesize()` was already called. **This is the highest-value hardening opportunity in the subsystem.** If a run dies without printing "Done", assume the lock is held.

**Caveat 3 — disk.** No quota or free-space check.

**Rolling back a bad run:**

```bash
rm -rf pub/media/falcosense/800x800
bin/magento config:set smart_search/image_compress/last_run_at ""
bin/magento cache:flush config
```

⚠️ **Other gaps:** no time budget / `set_time_limit`; **writes bypass Magento's `Filesystem` write API** (raw `mkdir`/`rename`/`copy`/`imagejpeg`), so this is **incompatible with Magento Remote Storage (S3/GCS)** — files would be written to a local disk nothing serves; no `--dry-run`; **no garbage collection** — nothing ever deletes a compressed file whose source product was removed, so the tree grows monotonically.

⚠️ **`ImageCompressionEngine` is not wired to the dedicated logger** — `di.xml` has no entry for it, so its per-file warnings ("Not a decodable image", "GD build has no WEBP support", "Re-encode failed") land in `var/log/debug.log`/`system.log` while the batch summaries land in `smartsearch-image-compress.log`. A one-line `di.xml` fix, and an easy trap when debugging.

### 15.5 WHY the module ships its own image compression

1. **Replacing Klevu's image cache.** `ProductImageCompressionService.php:15-19` says the tree is *"bucketed the same way the reference resize-images.php script buckets **Klevu-style** image caches"*, and the theme footer confirms *"KLEVU DISABLED — FalcoSense handles search now."* Klevu previously supplied a CDN-hosted pre-resized image for every card. Removing Klevu removed that. The `{c1}/{c2}/{filename}` sharding is Klevu's convention, kept deliberately so existing frontend code continued to work with only a base-URL change.
2. **Magento's own image cache was unusable here.** Card images come from the **API**, which returns image *paths*, not `Product` objects. Magento's `catalog/product/cache/{hash}/` pipeline needs a loaded product plus a `view.xml` image role and generates a per-role hash directory — you cannot construct that URL from a bare path string. What was needed was a **deterministic, hash-free, string-computable** URL that **both PHP and JavaScript** could build from the same input. One 800×800 tier plus 2-character sharding is the simplest scheme that satisfies that.
3. **Payload weight.** Card grids render up to 24 images at once; without a resized tier each would pull a multi-megabyte original — which is exactly what the minicart still does (§15.1).
4. **Server constraints** — GD only, no Imagick.
5. **Trust nothing about the source files.** The never-bigger-than-source guard, the extension-independent format detection and the verbatim-copy fallback all read as scars from a real catalogue of mislabelled, corrupt and already-optimised files.

---

## 16. Logging, monitoring and the debugging playbook

### 16.1 The five dedicated log files

Wired via `virtualType` loggers in `etc/di.xml:21-52` and injected into specific classes at `:55-94`.

| File under `var/log/` | Min level | Written by |
|---|---|---|
| `smartsearch-realtime.log` | **DEBUG** | `ProductSaveObserver`, `StockChangeObserver`, `ProductDeleteObserver`, **and `ProductSyncService`** |
| `smartsearch-full-sync.log` | INFO | `FullSyncCommand` (plus the admin button's shelled-out stdout/stderr) |
| `smartsearch-full-sync-error.log` | ERROR | same events, filtered |
| `smartsearch-image-compress.log` | INFO | `ProductImageCompressionService`, `FullSyncCommand` |
| `smartsearch-image-compress-error.log` | ERROR | same events, filtered |
| `smartsearch-analytics.log` | — | `CustomerEventService` and `PurchaseObserver` via **raw `file_put_contents`**, bypassing Monolog entirely (so **no logrotate**) |

⚠️ **Three traps in this table:**

1. **`ProductSyncService` writes to the *realtime* log even during a full sync.** So during a CLI run, the per-batch HTTP detail is in `smartsearch-realtime.log` while the orchestration summary is in `smartsearch-full-sync.log`. **You need both.**
2. **Several classes are NOT wired to a dedicated logger** and fall through to `var/log/system.log`: `Cron/ProductSync.php`, `Service/FullSyncService.php`, both queue consumers, `Cron/ImageCompress.php`, and `Service/ImageCompressionEngine.php`. If the cron or queue path is ever revived, none of its `[SmartSearch]` lines will appear where you expect.
3. **`[SmartSearch][BENCH]` and `[SmartSearch][PLP]` go to `system.log`**, because `FalcoSensePlpProvider` takes a plain `LoggerInterface`. That is **3 INFO lines per search and per category page view** (§7.9).

### 16.2 Grep cheat sheet

```bash
# Is the module trying at all?
grep '\[SmartSearch\]' var/log/smartsearch-realtime.log var/log/smartsearch-full-sync.log var/log/system.log

# SSR / PLP
grep '\[SmartSearch\]\[PLP\]'   var/log/system.log     # failures + slow-response warnings
grep '\[SmartSearch\]\[BENCH\]' var/log/system.log     # timing instrumentation
```

| Symptom | Grep for | Emitted at |
|---|---|---|
| SSR silently off | `Platform endpoint not configured` / `No search token available` | `FalcoSensePlpProvider.php:65`, `:71` |
| SSR timing out | `PLP request transport error (28) after 501ms` | `PlatformHttpClient.php:66-69` |
| SSR fragile (near budget) | ⚠️ `Slow platform response: 430ms (budget 500ms)` | `:91-97` |
| Token minting failed | `SearchTokenService: token fetch failed (HTTP …)` | `SearchTokenService.php:104` |
| Nothing configured | `Sync skipped: endpoint URL or API key not configured` | `ProductSyncService.php:37`, `:128`, `:194`, `:241` |
| Observer fired | `ProductSaveObserver fired` / `StockChangeObserver fired` / `ProductDeleteObserver fired` | `:47` / `:40` / `:62` |
| Silently rate-limited | `Rate limit reached (120/min)` | `ProductSaveObserver.php:60` |
| Dropped as irrelevant | `No relevant change for product` | `:73` |
| Real-time sync off | `Real-time sync disabled for store` | `:55` |
| Deleted instead of synced | `is disabled/out-of-stock … deleting from platform instead` | `ProductSyncService.php:56` |
| Skipped on price | `exceeds max sync price` | `:71` |
| The actual HTTP call | `POSTing product` / `DELETEing product` | `:85` / `:204` |
| HTTP failure body | `HTTP <code> from platform. URL: … Body:` | `:831`, `:875` |
| Full sync boundaries | `=== Full sync STARTED ===` / `=== Full sync FINISHED` | `FullSyncCommand.php:109` / `:149` |
| Fatal key error | `Invalid API key or connection error` | `:310` |
| Reconciliation refused | `Reconciliation: SKIPPED — would delete` | `:556` |
| Aborted by Stop | `Lock removed — aborting` | `:283`, `:347`, `:422`, `:462`, `:572` |
| Exclusion counts | `Excluding N …` | `:229`, `:237`, `:253`, `:278` |
| Events not arriving | `SKIP: SmartSearch disabled` / `SKIP: url or apiKey is empty` / `HTTP=<code>` | `CustomerEventService.php:211-269` |
| Storefront slow on PDP/cart | `[SmartSearch][Events] cURL error` | `:265` |
| Purchase events missing | `--- PurchaseObserver::execute() FIRED ---` | `PurchaseObserver.php:26` |

### 16.3 Check the filesystem, not just logs

```bash
cat var/SmartSearch/full_sync.lock            # {pid, source, started}
cat var/SmartSearch/last_sync_result.json     # {success, message, at}
ls -la /tmp/smartsearch_token_*.json          # the search-token cache
bin/magento config:show smart_search/cron/last_sync_at
bin/magento config:show smart_search/image_compress/last_run_at
```

### 16.4 Browser-side debugging

The search page logs a build marker on boot — `[FalcoSense PLP][search/results.phtml] build: scroll-fix-2026-08-14` (`search/results.phtml:633`) — which is the fastest way to confirm which version of the template is actually deployed.

**Debugging inside the shadow root** (§8.7): `document.querySelector('#fs-ssr-grid')` returns `null` on a *working* page. Use:

```js
const root = document.getElementById('fs-search-shadow-host').shadowRoot;
root.querySelector('[x-data^="ahySearchResults"]');
JSON.parse(root.getElementById('fs-ssr-payload').textContent);   // only before Alpine boots
```

**To confirm SSR is genuinely working** (not just that a shadow root exists): **View Source** (not Inspect) and check for a literal `<template shadowrootmode="open">` containing real product `<img>`/`<a>` markup, plus a `#fs-ssr-payload` script whose JSON has `"success":true` and real products. Then check the Network tab shows **no** `products` XHR on load.

### 16.5 A first-response triage table

| Report | Most likely cause | First check |
|---|---|---|
| "Search results are empty" | platform down, or token expired | `grep PLP var/log/system.log`; DevTools Network for a 401 on `/api/v1/products` |
| "Products appear then jump/resize" | the SSR→Alpine handoff (§7.5), or the image-host mismatch on category (§6.3) | Compare `#fs-ssr-grid`'s card HTML with the hydrated card |
| "Category page is slow" | Magento bootstrap (~89% of TTFB), **not** FalcoSense | `grep BENCH var/log/system.log` — if it's 63–132 ms, it isn't this module |
| "A new product isn't searchable" | real-time sync off; or rate-limited; or filtered by price/status/OOS | `grep -E 'ProductSaveObserver|Rate limit|exceeds max' var/log/smartsearch-realtime.log` |
| "A deleted product still shows" | delete failed, and there is no cron to reconcile | `grep DELETEing var/log/smartsearch-realtime.log`; then run the full sync |
| "Admin style change did nothing" | **S2** — no cache invalidation on style save | `bin/magento cache:flush full_page` |
| "Reverted to default but it's still applied" | **S1** — no `afterDelete()` | Inspect `falcosense_style_value` |
| "Slider is missing / not swipeable" | Swiper never loaded (§11.4), or a silent API failure (§11.3) | Console for `Swiper is undefined`; reproduce the `curl` by hand |
| "Options button does nothing" | on a **Collection** page: the event-name bug (§9.8) | Console: does `ahy-cfg-modal-open` fire? |
| "Blank page / fatal after deploy" | **stale OPcache serving an old Interceptor** (§13.5) | `var/log/exception.log` for a too-few-arguments error |

---

## 17. Security review

Consolidated findings, most severe first. Each is a real, currently-live observation with a citation.

| # | Severity | Finding | Location | Recommendation |
|---|---|---|---|---|
| 1 | 🔴 **Critical** | **The live Magento session ID is transmitted to a third-party SaaS on every tracked event**, and written to a plaintext, unrotated log. With Redis-backed sessions this is the real `PHPSESSID` value — anyone who can read the platform's event store, its access logs, or any proxy log along the path holds a **session-hijacking token**, including for logged-in customers mid-checkout. The class's otherwise careful GDPR docblock does not mention `session_id` at all, suggesting it was added without being weighed. | `Service/CustomerEventService.php:233` | Send `hash('sha256', session_id() . <server-side pepper>)`, or **drop the field** — `visitor_id` already provides the correlation it was presumably meant to supply. |
| 2 | 🔴 **Critical** | **`Observer/LogApiKey.php` logs the complete, unredacted API key** at INFO level: `$this->logger->info('[SmartSearch] API Key: ' . $apiKey);`. That key is the storefront's write access to the ingest API **and** the HMAC signing secret for every event. **Verified: it is registered to no event in `etc/events.xml`, `etc/frontend/events.xml` or `etc/di.xml`, so it has never run on this install.** It is a landmine, not an active leak. | `Observer/LogApiKey.php:24-25` | **Delete the file.** |
| 3 | 🟠 High | **API key stored in plaintext.** `type="password"` obscures the input; it does not encrypt. The key is readable in `core_config_data`, DB dumps, and `bin/magento config:show`. | `etc/adminhtml/system.xml:55-60` | Add `backend_model="Magento\Config\Model\Config\Backend\Encrypted"` with `type="obscure"`. |
| 4 | 🟠 High | **The API key travels in a URL query string** on the slider path — `'?api_key=' . urlencode($apiKey) . …`. Query strings land in access logs, proxy logs and Referer headers. The events path does this correctly, as a header. | `Block/Slider/Products.php:138-140`; `Block/Slider/Collection.php` | Move to an `X-Api-Key` header. |
| 5 | 🟠 High | **2-second blocking HTTP inside order placement**, in the window around persistence where Magento holds DB transactions and quote locks. Not an information-disclosure issue but a genuine availability risk on the highest-value transaction. | `CustomerEventService.php:256`; `PurchaseObserver.php:43-44`; `etc/events.xml:18-21` | Route through the existing RabbitMQ pipeline, or defer past `fastcgi_finish_request()`. |
| 6 | 🟠 High | **API-key prefix written to a plaintext log on every event** — `substr($apiKey, 0, 8) . '...'`. The same file also receives `session_id`-bearing context, and because it is written with raw `file_put_contents` it bypasses Monolog and any logrotate rule. | `CustomerEventService.php:205`, `:221` | Remove the key logging; route the file through Monolog. |
| 7 | 🟠 High | **Tracking cookie set with no consent check**, directly contradicting the service's own docblock, which states `_ahy_vid` *"requires a cookie consent banner on the storefront before this cookie is set."* The elaborate five-CMP sniffer gates only `ahy_geo_state` — whose consumer no longer exists. **The gating is on precisely the wrong cookie.** The layout node also has no `ifconfig`, so the cookie is set even with the module switched off. | `visitor_cookie.phtml:42-44`; `CustomerEventService.php:18-19`; `default.xml:38-41` | Gate `visitor_cookie.phtml` behind the same CMP sniffer; add `ifconfig`. |
| 8 | 🟠 High | **A third-party geolocation API is called from the visitor's browser**, disclosing their raw IP to `ipapi.co`. The consent gate **fails open** when no recognised CMP is present. And the resulting cookie is never read by anything (§13.4) — all of the risk, none of the benefit. | `geo-consent.phtml:52`, `:108-109` | Delete the geo machinery, or restore its consumer and close the fail-open path. |
| 9 | 🟡 Medium | **`X-Signature` HMAC is keyed by the API key itself**, so it provides body integrity but zero authentication beyond `X-Api-Key`. A separate `smart_search/webhook/secret` config path exists for exactly this purpose and has **zero callers**. | `CustomerEventService.php:252`; `ProductSyncService.php` | Use a distinct outbound signing secret. |
| 10 | 🟡 Medium | **`Controller/Suggest/Index.php` disables TLS certificate verification** — `CURLOPT_SSL_VERIFYPEER => false` — making that call trivially MITM-able. Mitigated only by the fact that **the controller is dead** (no caller). | `Controller/Suggest/Index.php:42` | Delete the controller. |
| 11 | 🟡 Medium | **`visitor_id` is client-controlled and unvalidated** — `$_COOKIE['_ahy_vid']` is forwarded verbatim with no format check and no length cap (unlike `user_agent`, which is truncated). A crafted cookie is a log-poisoning / profile-pollution vector on the platform side. | `CustomerEventService.php:232` | Validate as a UUID; reject otherwise. |
| 12 | 🟡 Medium | **`X-Forwarded-For` is trusted** for IP anonymisation, taking the first hop. Client-spoofable unless a trusted proxy sanitises it. | `CustomerEventService.php:304-306` | Use Magento's `RemoteAddress` with a configured trusted-proxy list. |
| 13 | 🟡 Medium | **`Ahy_ThemeCustomization`'s `pub/variant-attrs.php`** — a standalone script outside Magento's bootstrap that hard-codes DB credentials and runs raw SQL with no auth or ACL. It is blocked by an nginx 403 on production but reachable on dev2 (whose blanket HTTP Basic Auth lets the request through first). The module **already replaced it** with a proper controller, but references remain in dead code. | documented at `Controller/Configurable/Options.php:29-38`; referenced at `smart-slider.phtml:469` | Confirm the file is deleted from every environment. |
| 14 | 🟡 Medium | **ACL granularity is a single resource** covering credentials, sync triggers and visual styling together. A designer cannot be granted styling access without the API key field and the Full Sync button. And every group — **including Tools** — is store-view scoped, so a store-scoped admin can trigger a global full sync. | `etc/acl.xml`; `etc/adminhtml/system.xml` | Declare a `<resource>` per group. |
| 15 | 🟢 Low | **SVG accepted as an icon upload** with no content sanitisation. The render path uses `<img src>` where SVG scripts don't execute, so this is not itself XSS; the residual risk is direct media-URL access. | `Model/Config/Backend/StyleImage.php:46-49` | Sanitise, or drop SVG. |
| 16 | 🟢 Low | **`shell_exec` from the admin controller.** Inputs are `(int)`-cast and `escapeshellarg()`'d so it is **not injectable**, but the pattern requires `shell_exec` enabled and a resolvable `php` binary. | `Controller/Adminhtml/Sync/FullSync.php:63-72` | Acceptable; document it. |
| 17 | 🟢 Low | **`SyncAllButton` interpolates values into JS with `htmlspecialchars()`** rather than `json_encode` — the wrong escaping function for a JS string context. Values are module-controlled (lock `source` and `started`), so not currently exploitable. | `Block/Adminhtml/System/Config/SyncAllButton.php:33-34` | Use `json_encode`. |

**What the module gets right, and should be credited for:**

- The **search-token architecture** (§9.3) is textbook: the real API key never leaves the server, the browser gets only a short-lived token, and the `/tmp` cache is keyed by an API-key hash after a genuine cross-tenant leak was found and fixed.
- **XSS defence in the SSR payload** is correct: `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` on a `type="application/json"` island (`search/results.phtml:206`).
- **All merchant-supplied style values are `(int)`-cast or `in_array`-mapped** before reaching CSS (`global-style-vars.phtml:10-23`) — no CSS-injection surface.
- **Templates escape consistently** via `escapeHtml`/`escapeHtmlAttr`/`escapeUrl`/`escapeJs`, and the `$renderSsrCard` closures escape every interpolated field.
- The `Controller/Configurable/Options.php` docblock is a model of documenting *why* an insecure predecessor was replaced.

---

## 18. Integrating this module into another Magento site

This is the section to read if someone asks "can we sell this to another client?"

### 18.1 🔴 The honest verdict: as-is, no

**The module is not portable in its current state.** This is a known, documented limitation, not a hidden defect. Three independent reasons:

**Reason 1 — it ships zero CSS of its own.** There is no `view/frontend/web/` directory at all (verified). Every colour, spacing and shape comes from Tailwind utility classes and custom colour tokens (`ahy-blue`, `ahy-red`, `ahy-bg`, `ahy-stock-green`) that exist **only because the host theme's `tailwind.config.js` defines them and scans this module's templates as content**:

```js
// design/frontend/Ahy/Everest2/web/tailwind/tailwind.config.js:245
content: [ …, "../../../../../../../app/code/**/*.phtml", … ]
// and :60-70
colors: { ahy: { bg: '#efeadd', blue: '#0d2f47', red: '#d83a3a', 'stock-green': '#258635', … } }
```

**On a theme without matching config, or a non-Tailwind theme, this module's grids render completely unstyled.**

**Reason 2 — it hard-depends on Hyvä's JavaScript runtime.** `hyva.getFormKey()`, `hyva.getUenc()`, `hyva.formatPrice()`, `window.dispatchMessages()`, and the `reload-customer-section-data` / `toggle-cart` event contract. Alpine.js must be present and must boot before the module's inline scripts. `initWishlist()` — called by both sliders — **is not defined anywhere in this module or repo**; it comes from the Hyvä base theme.

**Reason 3 — it hard-depends on two sibling modules.** `Ahy_ThemeCustomization` supplies `/ahy_themecustomization/index/mediaGallery` (5 call sites) and `/ahy_themecustomization/index/resolveSuperAttribute` (3 call sites); without them the configurable-options modal breaks. Neither is declared in `etc/module.xml`. `Webkul_Marketplace` supplies the seller/brand data (`marketplace_product`, `marketplace_userdata`, queried by raw SQL) and the `marketplace_seller_profile` layout handle.

**Plus a long tail of Everest-specific hard-codes** (all verified by grep):

| Hard-code | Count / location |
|---|---|
| `https://static.everest.com/media/.thumbswysiwyg/everest-logo_2_.png` (fallback image) | **5 live places** |
| `https://static.everest.com/media/falcosense/800x800` (CDN base) | `category/results.phtml:656`, `collection/results.phtml:303` |
| `/media/icons/everest-loading-icon.gif` (loading spinner) | 4 templates |
| `'The Everest Marketplace'` (default seller name) | 8+ places |
| `FREE_SHIPPING_SELLERS: ['The Everest Marketplace','The Everest Collection']` | 3 templates |
| `https://dev2.everest.com/media/wysiwyg/falcosense-logo.png` | `VersionInfo.php:16` — **a dev2 URL rendered in the production admin** |
| `#efeadd`, `#0d2f47`, `#dc2626`, `#1e3a5f` | throughout, in inline styles |
| `q=outdoor` as the "popular products" query | `html/header/search-form.phtml:292` |
| `http://localhost:8080` endpoint fallback | `html/header/search-form.phtml:22` |
| `https://app.falcosense.com` platform fallback | `Block/Slider/Products.php:135`, `Block/Slider/Collection.php:36` |
| `$` + `number_format` price formatting, `$50`/`$100`/… price buckets | sliders, filters — **USD-only** |
| `.html` product URL suffix, root-level product URLs | `getProductUrl()` ×2, and in JS |
| `entity_type_id = 4` instead of resolving it | 3 places |

By contrast, `Ahy_SmartSearchLuma` was built to be portable — it ships its own `BASE_CSS`, isolates with `:host { all: initial }`, and detects host theme tokens. If portability is the goal, that module is the better starting point.

### 18.2 Prerequisites checklist

Before quoting any integration, confirm every line:

| # | Requirement | Verify with |
|---|---|---|
| 1 | Magento 2.4.x Open Source or Adobe Commerce | `bin/magento --version` |
| 2 | PHP 8.1+ with **GD** (Imagick is not used) | `php -m \| grep -E 'gd\|curl'` |
| 3 | **Hyvä theme** (this module's frontend assumes it) | `etc/hyva-themes.json` exists |
| 4 | **Alpine.js** available globally | Hyvä provides it |
| 5 | **Tailwind build** that scans `app/code/**/*.phtml` | the theme's `tailwind.config.js` |
| 6 | Custom Tailwind colour tokens: `ahy-bg`, `ahy-blue`, `ahy-blue-light`, `ahy-red`, `ahy-stock-green` | same file |
| 7 | **Swiper 11** loaded on any page carrying a slider | §11.4 |
| 8 | `Magento_MessageQueue`, `Magento_CatalogInventory`, `Magento_CatalogSearch`, `Magento_PageCache` | `bin/magento module:status` |
| 9 | **`Ahy_ThemeCustomization`** or equivalent endpoints | §18.1 Reason 3 |
| 10 | **`Webkul_Marketplace`** — or accept `brand` falling back to the EAV attribute | §12.5 |
| 11 | A **FalcoSense platform tenant**: endpoint URL + API key | from the FalcoSense team |
| 12 | Writable `/tmp` (token cache) and `var/SmartSearch/` at mode **0775** | §12.4 |
| 13 | `shell_exec` enabled, if the admin Sync button is wanted | `php -i \| grep disable_functions` |
| 14 | **Not** using Magento Remote Storage (S3/GCS) if image compression is wanted | §15.4 |
| 15 | No enforcing CSP without nonces | §14.7 S12 |

### 18.3 The integration steps

**Two scenarios, very different sizes. Do not conflate them.**

---

#### Scenario A — another Hyvä store on the *same* theme family (e.g. a sibling Everest site)

**13 steps, ~2–4 days.** This is essentially a redeploy.

| Step | Action | Command / detail |
|---|---|---|
1 | Copy the module | `app/code/FalcoSense/Search/` — **excluding all 214 `.bak*` files** |
2 | Confirm the theme's Tailwind config scans `app/code/**/*.phtml` and defines the `ahy-*` colours | §18.2 items 5–6 |
3 | Confirm `Ahy_ThemeCustomization` is present and its two endpoints respond | `curl -I /ahy_themecustomization/index/mediaGallery?sku=X` |
4 | Enable the module | `bin/magento module:enable FalcoSense_Search` |
5 | Run setup — **mandatory**, this creates the three style tables and seeds them | `bin/magento setup:upgrade` |
6 | Compile DI | `bin/magento setup:di:compile` |
7 | Deploy static content (the Tailwind rebuild picks up the module's templates) | `bin/magento setup:static-content:deploy -f` |
8 | Flush | `bin/magento cache:flush` |
9 | Configure: **Stores → Configuration → Ahy → FalcoSense** — set `endpoint_url`, `api_key`, `search_url`; set **Enable Real-Time Sync = Yes** (it is off by default, §5.5) |
10 | Set the hidden config values the admin doesn't expose | `bin/magento config:set smart_search/general/products_per_page 12`<br>`bin/magento config:set smart_search/plp/platform_timeout_ms 500` |
11 | **Verify the token mints** before anything else | `grep SearchTokenService var/log/system.log`; `ls /tmp/smartsearch_token_*` |
12 | Run the first full sync and watch it | `bin/magento smartsearch:sync:full --force`<br>`tail -f var/log/smartsearch-full-sync.log` |
13 | **Re-add a cron schedule** — there is none (§12.1). Decide the cadence, then add `<job>` entries to `etc/crontab.xml` and **first reconcile the delete-vs-upsert divergence** (§12.6) |

Then optionally: **step 14** — place sliders (create them in the platform admin, paste the CMS directive from §11.2, and **make sure Swiper is loaded on those pages**); **step 15** — run image compression (`bin/magento smartsearch:image:compress --limit=50` first).

---

#### Scenario B — a genuinely different Magento site (different theme, no Everest/Webkul/Ahy modules)

**This is a port, not an install. Roughly 20 steps across 4 phases, 4–8 weeks for one developer**, and the honest recommendation is to **start from `Ahy_SmartSearchLuma` instead**, because it has already solved the isolation problem.

**Phase 1 — Decouple (~2 weeks)**

1. Strip the `.bak` files and the dead code inventoried in §4.3 and §20 (~2,100 lines of dead templates + the 263-line dead block). This alone makes the rest tractable.
2. **Consolidate the product card.** Seven copies (§6.3) must become one. This is the single biggest piece of work and everything else depends on it.
3. **Extract one image-URL builder** — one PHP, one JS, one config value for the media host. Replace the four divergent copies (§15.1).
4. **Make every hard-coded value configurable**: fallback image, loading spinner, default seller name, free-shipping seller list, CDN host, price buckets, "popular products" query, product URL suffix.
5. **Replace the currency handling.** `'$' . number_format()` and the fixed `$50/$100/$250/$500/$1000` buckets must become locale- and currency-aware.
6. Add the missing dependencies to `etc/module.xml` (§5.1).

**Phase 2 — Reduce the theme coupling (~2 weeks)**

7. **Decide the CSS strategy.** Either (a) require Tailwind + document the exact token names the host must define, or (b) ship your own CSS — which is what `Ahy_SmartSearchLuma` does, and the reason it is more portable. Option (b) means rebuilding the visual design once, deliberately.
8. **Abstract the Hyvä JS contract** behind a small adapter: `getFormKey()`, `getUenc()`, `formatPrice()`, `showMessage()`, `refreshCart()`, `openCart()`. Provide a Hyvä implementation and a Luma/plain implementation.
9. **Replace the `Ahy_ThemeCustomization` endpoints** with your own controllers (a media-gallery endpoint and a super-attribute resolver). `Controller/Configurable/Options.php` is already the right pattern to copy.
10. **Make the Webkul brand lookup optional** — wrap `resolveMarketplaceBrand()` behind a capability check so it degrades to the EAV brand.
11. **Bundle Swiper** in the module's own layout, or replace it with CSS scroll-snap.

**Phase 3 — Fix the known defects (~1 week)** — everything marked 🔴 in §20, at minimum:

12. Remove `Observer/LogApiKey.php`; hash or drop `session_id`; encrypt the API key.
13. Move analytics off the checkout critical path.
14. Add `afterDelete()` to the style backend models; invalidate FPC on style save.
15. Fix the collection grid's modal event name; fix `frontend_enabled` so it actually gates.
16. Add the memory guard to the image engine.

**Phase 4 — Re-architect what a new site will need (~1–3 weeks)**

17. **Add PLP caching.** `cacheable="false"` on every category page (§7.6) is acceptable on a site with this one's traffic shape and infrastructure; on a different site it may be untenable. `PlpQuery::cacheKey()` already exists and is unused — the target is `Ahy_SmartSearchLuma`'s `CachedPlpProvider` + custom cache type + `CacheInvalidator` + `PlpCacheWarmer`.
18. **Decide the Shadow DOM question deliberately** (§8.2). For a new site, either commit to full isolation (the sibling module's `adoptedStyleSheets` approach) or drop it entirely. The current half-measure inherits the crawler risk without the isolation benefit.
19. **Re-add cron**, having first reconciled §12.6.
20. **Add tests.** The module's only test file currently sits in a `.bak`-suffixed directory. The sibling module has 9 unit tests covering exactly the PLP data layer that matters most.

### 18.4 Effort summary

| Scenario | Steps | Effort (1 mid-level Magento dev) | Confidence |
|---|---|---|---|
| **A** — another Hyvä/Everest-family store | **13** (+2 optional) | **2–4 days** | High — this is a redeploy |
| **B** — a different Magento + Hyvä site | **~20**, 4 phases | **4–8 weeks** | Medium |
| **C** — a non-Hyvä (Luma) site | — | **8–12 weeks** — the frontend is effectively a rewrite | Low. **Recommend starting from `Ahy_SmartSearchLuma`.** |

Excluded from all estimates: FalcoSense platform-side onboarding (tenant, API key, index build, relevance tuning), and the first full catalogue sync (a function of catalogue size — at 300/batch and ~85 ms per platform round-trip plus normalisation, budget hours for 150k products).

### 18.5 If you do only three things first

1. **Delete the `.bak` files and the dead code.** 214 backup files, ~2,100 lines of unreachable live code. Everything else is easier afterwards, and `grep` starts telling the truth.
2. **Consolidate the product card to one definition.** Seven copies is the root cause of most visual bugs, and Phase 1's other steps all touch it.
3. **Fix the two 🔴 security items** (`LogApiKey`, `session_id`) and move analytics off the checkout path. These are small, self-contained, and materially reduce risk today regardless of any porting decision.

---

## 19. Operational runbook

### 19.1 Deploy command matrix

| Change | Commands needed |
|---|---|
| `.phtml` only | `bin/magento cache:flush` |
| Layout XML only | `bin/magento cache:flush` |
| PHP class **without** a constructor change | `bin/magento cache:flush` |
| PHP class **with** a constructor change | `bin/magento setup:di:compile` **then** `cache:flush` — ⚠️ see §19.2 |
| New `di.xml` preference / virtualType | `setup:di:compile` + `cache:flush` |
| New `system.xml` field **backed by a style attribute** | Add the `UpgradeData` row, bump `setup_version`, then `setup:upgrade` + `setup:di:compile` + `cache:flush` |
| `config.xml` default change | `cache:flush` (config cache) |
| **A style value saved in admin** | ⚠️ `bin/magento cache:flush full_page` — **nothing does this automatically** (§14.7 S2) |
| New static asset (CSS/JS/image) | `setup:static-content:deploy -f` + `cache:flush` |
| Tailwind class added to a `.phtml` | Rebuild the theme's Tailwind, then `setup:static-content:deploy -f` |

Note: `setup:upgrade` and `setup:static-content:deploy -f` were **not** needed for any of the SSR/Shadow DOM work, because it changed no schema and no static asset (the shadow root reuses the theme's *existing* compiled `styles.css` via a `<link>`).

### 19.2 🔴 The constructor-change deployment gotcha

**Repeat this to every new developer.** From `Block/Search.php:191-211` (quoted in full in §13.5):

> *"adding a new constructor argument here previously required the auto-generated Interceptor class to be regenerated, and a stale OPcache serving the old interceptor bytecode broke the live search box until it self-resolved, with no way to force a PHP-FPM restart."*

**Symptom:** a fatal error or blank page after a constructor-signature change, even though `setup:di:compile` and `cache:flush` both ran cleanly.
**Diagnosis:** `var/log/exception.log` shows a **too-few-arguments / missing-argument** error naming an `Interceptor` class.
**Mitigation, in order of preference:**
1. Avoid touching constructors on hot frontend classes — reach the dependency another way (as `getCustomerGeoCountry()` does).
2. If you must, deploy during low traffic and restart PHP-FPM / `opcache_reset()` if you have any means to.
3. If neither is possible, expect a window until OPcache expires on its own.

### 19.3 Routine operations

```bash
# ── Full catalogue sync ─────────────────────────────────────────
bin/magento smartsearch:sync:full --force
tail -f var/log/smartsearch-full-sync.log

# Resume after an interruption (does NOT advance the delta cursor)
bin/magento smartsearch:sync:full --force --page=214

# One store only
bin/magento smartsearch:sync:full --force --store=1

# ── Image compression ───────────────────────────────────────────
bin/magento smartsearch:image:compress --limit=50      # probe first
bin/magento smartsearch:image:compress --limit=2000    # then scale, off-peak

# ── State inspection ────────────────────────────────────────────
cat var/SmartSearch/full_sync.lock
cat var/SmartSearch/last_sync_result.json
bin/magento config:show smart_search/cron/last_sync_at
ls -la /tmp/smartsearch_token_*.json

# ── Force a token re-mint ───────────────────────────────────────
rm -f /tmp/smartsearch_token_*.json

# ── Recover a stuck lock (after a crash; verify the PID is dead first) ──
cat var/SmartSearch/full_sync.lock       # check the pid
ps -p <pid>                              # confirm it is gone
rm -f var/SmartSearch/full_sync.lock
```

### 19.4 Emergency: turn the module off

There is **no single kill switch** that stops everything. In order of blast radius:

| Goal | Action | Caveat |
|---|---|---|
| Stop SSR on search | `Block/Search.php:61` → `AB_DISABLE_SSR = true`, then `setup:di:compile` is **not** needed (it's a const), just `cache:flush` | Category SSR is **not** covered — it has no switch |
| Stop the Shadow DOM wrap | Edit `search/results.phtml`: remove lines 89–91 and 564–565 | No switch exists |
| Stop all sync | Admin: **Enable Sync = No** | Does **not** stop the CLI, which never checks it |
| Stop real-time sync only | Admin: **Enable Real-Time Sync = No** | With no cron, this means **nothing syncs** |
| Stop analytics | Admin: **Enable Sync = No** | Same switch as sync — you cannot separate them; and the tracking cookie is still set |
| Stop the custom frontend UI | ⚠️ **"Enable Frontend UI = No" does nothing** (§14.7, §11.6). The only real option is `bin/magento module:disable FalcoSense_Search` | Reverts search/category to Magento's native rendering |

**This is itself a finding:** the module has six things you might want to disable independently and two working switches, one of which is mislabelled.

### 19.5 Monitoring: what to alert on

| Signal | Where | Threshold |
|---|---|---|
| `[SmartSearch][PLP] Slow platform response` | `system.log` | any occurrence — SSR is within 20% of giving up |
| `[SmartSearch][PLP] PLP request transport error` | `system.log` | rate > a few/minute — SSR is failing open to CSR |
| `[SmartSearch][Events] cURL error` | `system.log` | any sustained rate — **every storefront interaction is paying +2 s** |
| `=== Full sync FINISHED (with failures)` | `smartsearch-full-sync.log` | any |
| `Reconciliation: SKIPPED — would delete` | `smartsearch-full-sync.log` | any — a safety guard tripped; investigate before overriding |
| Age of `smart_search/cron/last_sync_at` | config | **> 24 h** — with no cron, this is your only staleness signal |
| Presence of `var/SmartSearch/full_sync.lock` with a dead PID | filesystem | > 5 min |
| `smartsearch-image-compress-error.log` growth | filesystem | any |

---

## 20. Known issues and prioritised backlog

Consolidated from this whole review. Every item is verified with a citation in the section referenced.

### 🔴 P0 — do these first

| # | Item | Why | §|
|---|---|---|---|
| 1 | **Delete the 214 `.bak*` files** (5.8 MB, more backups than real files) | They are deployed, scanned by the Tailwind build, and make every `grep` and file search in the module lie | §1.5, §4.3 |
| 2 | **Delete `Observer/LogApiKey.php`** | Logs the full API key. Currently unregistered, so it is a landmine not a leak — but it must not survive | §17 #2 |
| 3 | **Hash or drop `session_id` in analytics payloads** | A live session-hijacking token is being sent to a third party and written to a plaintext log | §17 #1 |
| 4 | **Move analytics off the checkout critical path** | 2 s blocking HTTP inside `sales_order_place_after`, before order persistence | §13.3 |
| 5 | **Add `afterDelete()` to `StyleValue`/`StyleImage`** | "Use Default" is currently a one-way door — the storefront keeps a deleted override forever, unfixable from the UI | §14.7 S1 |
| 6 | **Invalidate FPC when a style value is saved** | "I saved and nothing happened" is unavoidable today, with no banner to hint at a flush | §14.7 S2 |
| 7 | **Add a pre-decode dimension/memory guard to `ImageCompressionEngine`** | An oversized image fatals the process and strands the lock for a full hour | §15.4 |
| 8 | **Fix the collection grid's modal event name** (`ahy-open-config-modal` → `ahy-cfg-modal-open`, 2 lines) | "Options" does nothing on Collection pages | §9.8 |
| 9 | **Make `frontend_enabled` actually gate the widgets** | The admin field promises a fallback to native search and delivers nothing | §11.6, §19.4 |
| 10 | **Decide the cron question** | There is no cron. With real-time sync off by default, a fresh install syncs **nothing**. Reconcile §12.6's delete-vs-upsert divergence first | §12.1 |

### 🟠 P1 — high value, low-to-moderate risk

| # | Item | § |
|---|---|---|
| 11 | Fix the search page's `setSort()` so it refetches (category is the reference implementation) | §9.2 |
| 12 | Make the columns-per-row setting work on the search page — duplicate `global-style-vars` CSS into the shadow root, and add the `ahy-product-grid` class to the SSR grid | §8.7, §14.7 S4 |
| 13 | Reconcile the category image host — the SSR card and the hydrated card request the same image from two different hosts | §6.3, §10 |
| 14 | Add error logging to both slider blocks' cURL failure paths — a missing slider is currently undiagnosable from logs | §11.3 |
| 15 | Declare Swiper in the module's own layout instead of relying on the PDP template | §11.4 |
| 16 | Decide the FPC story for slider-bearing CMS pages — geo-personalised HTML is currently cached and served to everyone | §11.5 |
| 17 | Wire `ImageCompressionEngine` to `ImageCompressLoggerVirtual` (one `di.xml` entry) | §15.4 |
| 18 | Add `<style>` fallbacks (`var(--ahy-grid-gap, 2px)`) or move `global-style-vars` to `<head>` — the SSR-card FOUC is real now that SSR exists | §14.7 S5 |
| 19 | Encrypt the API key; move it out of the slider's query string | §17 #3, #4 |
| 20 | Remove the `AB_DISABLE_SSR` kill switch and the `[BENCH]` logging, or make them permanent and configurable | §7.9 |
| 21 | Add a Shadow DOM kill switch, or remove the Shadow DOM | §8.7 |
| 22 | Gate `visitor_cookie.phtml` behind the CMP sniffer; add `ifconfig` | §17 #7 |
| 23 | Either restore the `ahy_geo_state` reader and wire up `getCustomerGeoCountry()`, or delete the geo machinery | §13.4, §13.5 |
| 24 | De-duplicate `add_to_cart` / `wishlist_add` (drop the client beacons — the server observers already cover both) | §13.6 |
| 25 | Fix `PurchaseObserver` to use `$order->getStoreId()` and to skip non-frontend orders | §13.6 |

### 🟡 P2 — cleanup and consolidation

| # | Item | § |
|---|---|---|
| 26 | Delete the dead code: `category/search-form.phtml` (1,627 lines), `search/autocomplete.phtml` (396) + the misplaced `search/default.xml`, `Controller/Suggest/Index.php`, `Helper/ProductImage.php`, `Observer/SearchQueryObserver.php`, `Service/FullSyncService.php`, `smart-slider.phtml:313-576` (263 lines), the empty `Magento_Theme` override dir, and `Block/.../SliderInfo.php` — **or** wire `SliderInfo` into `system.xml`, since it holds the only documentation of how to place a slider | §4.3, §11.2, §12.9 |
| 27 | Consolidate the product card from seven copies to one | §6.3 |
| 28 | Extract one image-URL builder (one PHP, one JS) and one config value for the media host | §15.1 |
| 29 | Hoist `parent::getImages()` out of the loop in `Model/Cart/ImageProvider`; use a resized URL | §15.1 |
| 30 | Memoise `Model/StyleConfig/Reader` (~50 queries per uncached render today) | §14.7 S10 |
| 31 | Consolidate the six copies of the `parse_url` → rebuild-base logic onto `Helper\Data::buildPlatformUrl()` | §5.5 |
| 32 | Remove the `hidden` class from `collection/results.phtml:41` (the item counter is permanently invisible) | §11.6 |
| 33 | Add `system.xml` fields for the hidden config paths that matter: `products_per_page`, `plp/platform_timeout_ms`, the `image_compress` group, the slider slugs | §5.5 |
| 34 | Split the ACL into per-group resources; restrict Tools to default scope | §14.6 |
| 35 | Fix the `getPlatformStoreId()` vs Magento-store-ID confusion at the three call sites that pass the wrong one | §5.5, §9.3, §11.6 |
| 36 | Reconcile the two slider templates' diverging CSS and Swiper config; make `$sliderId` unique per block instance | §11.4, §11.6 |
| 37 | Use `hyva.formatPrice()` in the sliders instead of `'$' . number_format()` | §11.6 |
| 38 | Update the stale comments: `catalogsearch_result_index.xml:14` (form target), `global-style-vars.phtml:2-9` (FOUC premise), `ProductViewObserver.php:19` (nonexistent proxy), the "cron will sync" messages in four files | §5.3, §14.7 S5, §13.6, §12.1 |

### 🟢 P3 — strategic

| # | Item | § |
|---|---|---|
| 39 | **Add PLP caching** — `PlpQuery::cacheKey()` already exists and is unused; `Ahy_SmartSearchLuma` has the full pattern (`CachedPlpProvider`, a custom cache type, `CacheInvalidator`, `PlpCacheWarmer`). This is the answer to `cacheable="false"` on every category page | §7.6 |
| 40 | Add a `Server-Timing` response header so FalcoSense's contribution is visible in DevTools instead of requiring a log check (proposed previously, never built) | §7.8 |
| 41 | Investigate Magento's own ~700 ms–3 s bootstrap on dev2 — confirmed to be ~89% of category TTFB and **confirmed not to be this module's fault**. Deploy mode, OPcache and PHP-FPM config are the named suspects | §7.8 |
| 42 | Resolve the Shadow DOM crawler-visibility question properly, or drop the Shadow DOM | §8.2 |
| 43 | Add tests. The module's only test file sits in a `.bak`-suffixed directory; the sibling module has 9 unit tests covering exactly the PLP data layer that matters most | §18.3 |
| 44 | Restore or delete the message-queue layer; if restoring, add the missing `falcosense.search.full_sync` topology binding first | §12.9 |
| 45 | Unify the naming: `FalcoSense_Search` / `smart_search` / `SmartSearch` / `Ahy` / "Smart Search Configuration" are five names for one product | §12.10 |

---

## 21. Glossary

| Term | Meaning in this codebase |
|---|---|
| **Alpine.js** | A small JS library that adds reactivity to plain HTML via `x-*` attributes. The standard for Hyvä themes; no build step. Every dynamic grid in this module is an Alpine component. |
| **AEO** | Answer Engine Optimisation — being crawlable by AI assistants (GPTBot, ClaudeBot). The reason the Shadow DOM decision was contentious (§8.2). |
| **Block** | A Magento PHP class that prepares data for one template. The template's `$block` variable *is* that instance. |
| **Canonical view** | Page 1, no filters, no custom sort, no `bypass_spell`. The only view search renders server-side (§7.4). |
| **`cacheable="false"`** | A layout attribute that makes Magento mark the **whole page** non-cacheable. Mandatory once a block renders per-query content (§7.6). |
| **CSR** | Client-Side Rendering — the browser fetches data and builds the DOM. What this module did everywhere before the SSR work. |
| **CSS custom property** | A `--variable` in CSS. **Inherits through a shadow boundary**, unlike a stylesheet — which is why `--ahy-card-radius` still works inside the shadow root (§8.6). |
| **Declarative Shadow DOM (DSD)** | `<template shadowrootmode="open">`. The HTML parser promotes it to a real shadow root during parsing, **before any JS runs** — the property that made it compatible with SSR (§8.1). |
| **Facet** | A filter group returned by the platform (`brand`, `price`, `size`), with option values and counts. Replaces Magento's layered navigation here. |
| **FPC / Full Page Cache** | Magento's whole-page HTML cache (or Varnish). Bypassed entirely on search and category pages by design (§7.6). |
| **Hyvä** | A Tailwind + Alpine.js Magento frontend theme, replacing Magento's Knockout/RequireJS stack. This module's frontend assumes it. |
| **Interceptor** | An auto-generated subclass Magento uses to apply plugins. Its constructor mirrors the original's — which is why constructor changes plus a stale OPcache once broke the live search box (§19.2). |
| **`isUsable()`** | On `PlpResult`: "we have real products". An empty-but-successful response is deliberately **not** usable, so a zero-result search falls through to the client-side zero-results UI (§7.3). |
| **Layer / LayerResolver** | Magento's category-navigation abstraction. `PageContext` uses it to identify the current category (§7.4). |
| **Layout XML** | Declarative config saying which blocks appear on which page. The **file name is the page** (§4.1). |
| **PLP** | Product Listing Page — a category page or a search results page. |
| **Port / Adapter** | `Api\PlpDataProviderInterface` is the *port* (the contract); `Service\Plp\FalcoSensePlpProvider` is the *adapter* (the implementation). Swappable via one `di.xml` line (§7.2). |
| **`preference`** | A `di.xml` directive that globally substitutes one class for another. This module has three, one of which replaces Magento's own search controller (§5.2). |
| **Search token** | A short-lived credential the browser uses instead of the real API key (§9.3). |
| **Shadow DOM** | A browser-enforced boundary isolating a DOM subtree's styles. Applied here to the search results page only (§8). |
| **SSR** | Server-Side Rendering. Here specifically: PHP makes the same API call the JS would have made, renders real HTML, and embeds the data for the JS to adopt (§7.1). |
| **Store view / scope** | Magento's multi-site hierarchy: default → website → store view. Config values resolve down that chain. |
| **`x-cloak` / `x-for` / `x-teleport`** | Alpine attributes: hide-until-booted / loop / move this node elsewhere in the DOM. `x-teleport` **escapes the shadow boundary** (§8.7). |

---

## 22. Appendices

### A. Live file inventory (134 files, `.bak*` excluded)

Ranked by size; ⚠️ marks a file with a live finding, 🧊 dead code.

| Lines | File | Note |
|---|---|---|
| 1627 | `view/frontend/templates/category/search-form.phtml` | 🧊 **orphaned — the largest file in the module** |
| 1217 | `view/frontend/templates/search/results.phtml` | ⚠️ SSR + Shadow DOM + the main Alpine component |
| 997 | `Service/ProductSyncService.php` | the ingest core |
| 828 | `view/frontend/templates/category/results.phtml` | ⚠️ SSR, no Shadow DOM; hard-coded CDN host |
| 813 | `view/frontend/templates/html/header/search-form.phtml` | ⚠️ takeover overlay; `localhost:8080` fallback |
| 736 | `Console/Command/FullSyncCommand.php` | 5-pass full sync |
| 641 | `view/frontend/templates/modal/config-modal.phtml` | configurable options modal |
| 576 | `view/frontend/templates/slider/smart-slider.phtml` | ⚠️ 263 lines dead (`:313-576`) |
| 396 | `view/frontend/templates/search/autocomplete.phtml` | 🧊 dead |
| 395 | `view/frontend/templates/collection/results.phtml` | ⚠️ modal event-name bug |
| 362 | `Cron/ProductSync.php` | 🧊 **unscheduled** |
| 330 | `Service/FullSyncService.php` | 🧊 zero callers; retains a PHP-8 bug |
| 329 | `Service/CustomerEventService.php` | ⚠️ sends `session_id` |
| 316 | `Service/Plp/FalcoSensePlpProvider.php` | ⚠️ live `[BENCH]` logging |
| 268 | `view/frontend/templates/slider/collection.phtml` | |
| 263 | `Block/Search.php` | ⚠️ `AB_DISABLE_SSR`; the OPcache war story |
| 252 | `Helper/Data.php` | all config access |
| 238 | `Block/Adminhtml/System/Config/SyncAllButton.php` | |
| 229 | `Service/DisabledParentResolver.php` | ⚠️ `$storeId` ignored in one method |
| 214 | `Service/ImageCompressionEngine.php` | ⚠️ no memory guard |
| 212 | `Service/SyncLockManager.php` | |
| 201 | `Setup/UpgradeSchema.php` | the 3 style tables |
| 200 | `Block/Slider/Products.php` | ⚠️ API key in the query string |
| 181 | `Service/ProductImageCompressionService.php` | |
| 177 | `etc/adminhtml/system.xml` | |
| 162 | `Model/Plp/PageContext.php` | the SSR gate |
| 162 | `Block/Adminhtml/System/Config/CardPreview.php` | ⚠️ duplicated literals |
| 160 | `Setup/UpgradeData.php` | style seeds |
| 151 | `Block/Slider/Collection.php` | |
| 148 | `view/frontend/templates/geo-consent.phtml` | ⚠️ write-only pipeline |
| 144 | `Controller/Configurable/Options.php` | best-documented class in the module |
| 143 | `Service/DuplicateSkuResolver.php` | ⚠️ loads the whole catalogue |
| 140 | `Observer/ProductSaveObserver.php` | |
| 135 | `Observer/StockChangeObserver.php` | |
| 128/125 | `view/.../search/product-card-{desktop,mobile}.phtml` | ⚠️ 2 of 7 card copies |
| 127 | `Service/SearchTokenService.php` | the cross-tenant fix |
| 124 | `Block/Category.php` | ⚠️ duplicates `buildPlatformUrl()` |
| 122/105 | `view/.../html/header/product-card-{desktop,mobile}.phtml` | ⚠️ 2 more card copies |
| 111/105 | `view/.../category/product-card-{desktop,mobile}.phtml` | ⚠️ 2 more card copies |
| 105 | `etc/di.xml` | 3 preferences, 3 virtual loggers |
| 103 | `Model/StyleConfig/Reader.php` | ⚠️ uncached |
| 102 | `Service/Plp/PlatformHttpClient.php` | the only ms-timeout client |
| 102 | `Observer/ProductDeleteObserver.php` | |
| 101 | `Model/Plp/PlpQuery.php` | ⚠️ `cacheKey()` unused |
| 99 | `Observer/SearchQueryObserver.php` | 🧊 registered nowhere |
| 99 | `Observer/PurchaseObserver.php` | ⚠️ wrong store ID |
| 90 | `Controller/Adminhtml/Sync/FullSync.php` | ⚠️ `shell_exec` + `env -i` |
| 83 | `Helper/ProductImage.php` | 🧊 zero references |
| 82 | `Model/Plp/PlpResult.php` | `isUsable()` semantics |
| 76 | `Block/Collection.php` | |
| 71 | `Block/Adminhtml/System/Config/SliderInfo.php` | 🧊 orphaned — holds the only slider docs |
| 69 | `Model/Plp/PlpItem.php` | wire-format-matched `toArray()` |
| 66 | `Controller/Suggest/Index.php` | 🧊 dead + `SSL_VERIFYPEER=false` |
| 62 | `Model/WebhookConsumer.php` | 🧊 never fed |
| 61 | `Observer/ProductViewObserver.php` | ⚠️ docblock claims a nonexistent proxy |
| 57 | `view/frontend/layout/default.xml` | every page |
| 55 | `view/frontend/layout/catalog_category_view.xml` | |
| 43 | `Model/Plp/PlpFacet.php` | |
| 35 | `view/frontend/templates/global-style-vars.phtml` | ⚠️ FOUC; no `var()` fallbacks |
| 33 | `Model/AttributeChangeDetector.php` | ⚠️ watches only 5 attributes |
| 30 | `Block/HeaderSearchForm.php` | the layout-merge workaround |
| 27 | `Observer/LogApiKey.php` | 🔴 logs the API key; unregistered |
| 22 | `view/frontend/templates/search/default.xml` | 🧊 **a layout file in `templates/`** |
| 21 | `Api/PlpDataProviderInterface.php` | the MUST-NOT-THROW contract |
| 11 | `etc/crontab.xml` | 🔴 **empty** |
| 8 | `Block/GeoConsent.php` | an empty subclass |

Plus: 15 observers, 3 loggers + 5 handlers, 3 config backend/source models, 8 remaining layout/template partials, 8 `etc/*.xml`, and `registration.php`.

### B. Platform API endpoint inventory

| Endpoint | Verb | Called from | Auth |
|---|---|---|---|
| `/api/v1/auth/token` | POST | PHP `SearchTokenService` | `X-Api-Key` header |
| `/api/v1/products` | GET | **PHP (SSR)** + browser (CSR), 5 components | `search_token` param |
| `/api/v1/products/collection` | GET | PHP sliders + browser collection grid | `api_key` param ⚠️ / `search_token` |
| `/api/v1/product` | GET | browser (config modal) | `search_token` |
| `/api/v1/sliders/{slug}` | GET | PHP `Block\Slider\Products` | `api_key` in the query string ⚠️ |
| `/api/v1/suggest` | GET | browser + dead PHP controller | `search_token` |
| `/api/v1/analytics/search` | POST | browser | `search_token` in body |
| `/api/v1/events` | POST | browser (`sendBeacon`) + PHP (`CustomerEventService`) | `search_token` / `X-Api-Key` + `X-Signature` |
| `/api/v1/ingest/products` | POST / DELETE / GET | PHP `ProductSyncService` | `X-Api-Key` + `X-Signature` |

### C. Magento events consumed

| Event | Area | Observer | Purpose |
|---|---|---|---|
| `catalog_product_save_after` | global | `ProductSaveObserver` | sync |
| `cataloginventory_stock_item_save_after` | global | `StockChangeObserver` | sync |
| `catalog_product_delete_after` | global | `ProductDeleteObserver` | sync |
| `sales_order_place_after` | global | `PurchaseObserver` | analytics |
| `customer_login` | frontend | `CustomerLoginObserver` | analytics |
| `customer_register_success` | frontend | `CustomerRegisterObserver` | analytics |
| `customer_logout` | frontend | `CustomerLogoutObserver` | analytics |
| `catalog_controller_product_view` | frontend | `ProductViewObserver` | analytics |
| `checkout_cart_product_add_after` | frontend | `AddToCartObserver` | analytics |
| `sales_quote_remove_item` | frontend | `RemoveFromCartObserver` | analytics |
| `wishlist_add_product` | frontend | `WishlistAddObserver` | analytics |
| *(none)* | — | `SearchQueryObserver` 🧊, `LogApiKey` 🧊 | unregistered |

### D. Browser custom events (the cross-component bus)

| Event | Dispatched by | Listened for by |
|---|---|---|
| `ahy-modal-search` | header input; search page on zero results | header overlay |
| `ahy-modal-open` / `ahy-modal-close` | header input | header overlay |
| `ahy-cfg-modal-open` | search + category cards, both sliders | `config-modal.phtml` ✅ |
| `ahy-open-config-modal` | ⚠️ collection grid | the orphaned `category/search-form.phtml` and dead slider code only — **broken** |
| `ahy-token-refreshed` | `window.ahyTokenRefresh.refresh()` | every Alpine component |
| `reload-customer-section-data` | every add-to-cart / wishlist path | **Hyvä theme** |
| `toggle-cart` | every add-to-cart path | **Hyvä theme** |
| `messages-loaded` | Hyvä theme | search page's cart-notice component |
| `alpine:init` / `alpine:initialized` | Alpine | the three `x-cloak` fallback handlers |

### E. Prior documentation in these repositories

| Document | Location | Content |
|---|---|---|
| `FALCOSENSE-SEARCH-SSR-HANDOFF.md` | `dev2-app/` (37 KB) | The definitive record of the Sept 2026 SSR + Shadow DOM work: decisions, benchmark data, bugs fixed, open items. **Read alongside this document.** |
| `PLP-CSS-ISOLATION-HANDOFF.md` | `falcosense-shadowdom-module/` | The sibling module's CSS-isolation strategy |
| `FALCOSENSE-TARGET-ARCHITECTURE.md` | same (30 KB) | The intended end-state architecture |
| `MAGENTO-FALCOSENSE-GUIDE.md` | same (53 KB) | The longest existing document |
| `FALCOSENSE-SHADOWDOM-IMPLEMENTATION-PLAN.md` | same | The sibling's Shadow DOM plan |
| `FALCOSENSE-PLP-ISR-BUILD.md` | same | Incremental Static Regeneration / PLP caching design — **the reference for backlog #39** |
| `FALCOSENSE-EVEREST-ISSUES-AND-FIXES.md` | same (29 KB) | Issue log |
| `FALCOSENSE-PITCH-DECK.md`, `FALCOSENSE-PRESENTER-GUIDE.md` | same | Commercial material |
| `INSTALL.md`, `DEV2-SEARCH-FIXES.md` | same | Install notes, fix log |
| `falcosense.architecture-diagram.pdf` | same | Architecture diagram |

---

## Document provenance

Assembled 2026-09-08 by reading the live source tree of `code/FalcoSense/Search` file by file (all 214 `.bak*` files deliberately excluded), cross-checked against `FALCOSENSE-SEARCH-SSR-HANDOFF.md`, the sibling `Ahy_SmartSearchLuma` repository, the host theme's Tailwind configuration, and `etc/env.php` / `etc/config.php`.

**How to keep this document honest:**

- Every claim carries a `file:line` citation. If a citation no longer matches, treat the claim as stale and re-verify rather than trusting it.
- Anything marked ⚠️ or 🔴 was true on 2026-09-08. **Re-check before acting**, especially the 🔴 items — some are small enough that someone may have fixed them.
- **Never treat "the code says X" as proof that "the server is running X."** This exact confusion cost a session: the deployed file had `AB_DISABLE_SSR = true` while the repo said `false`, so what was being tested in the browser did not match what the code said. Verify against the deployed file or the actual HTTP response.
- The single fastest orientation check on any environment: `bin/magento module:status | grep -i -E 'falcosense|smartsearch'` to confirm **which** module is live (§2), then View Source on a search page to confirm whether SSR and the Shadow DOM are actually active (§16.4).
