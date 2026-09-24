/**
 * FalcoSense — search results component.
 * ---------------------------------------------------------------------------
 * Alpine component behind the /fs/search listing and the header search overlay.
 * Exposed as a global factory so a host template can mount it with
 *
 *     x-data="ahySearchResults(apiUrl, token, query, storeId, analyticsUrl, geoState)"
 *
 * WHY THIS IS A FILE AND NOT AN INLINE <script>
 * ---------------------------------------------
 * It used to live inside search/results.phtml. That meant a storefront wanting
 * its own product markup had to delete our block — and the component went with
 * it. The only way to keep our search behaviour was to copy the whole component
 * into their template, which is what Everest's Ahy_PlpRevamp module did: a
 * ~480-line duplicate that then drifted ~38% away from ours.
 *
 * As a standalone file the two concerns separate cleanly: we own the behaviour,
 * the host owns the markup, and engine fixes reach every storefront without a
 * hand-port.
 *
 * MERGED FROM TWO FORKS
 * ---------------------
 * This is a reconciliation of FalcoSense's version and Ahy_PlpRevamp's. Where
 * they differed, the choice is recorded at the method. In summary:
 *   - from PlpRevamp: _writeUrl() and its call sites; URL state restore in
 *     init(); isFallbackImg() comparing the resolved url.
 *   - kept from FalcoSense: the shadow-DOM-aware lookups and SSR payload seeding
 *     (PlpRevamp renders in light DOM and has neither); goToPage()'s
 *     scroll-after-fetch ordering; the Array.isArray guard in sortedResults().
 *
 * NO STORE-SPECIFIC VALUES
 * ------------------------
 * Seller names, fallback image and CDN origin were hardcoded to Everest. They
 * now come from FalcoSense.config — see web/js/plp/runtime.js. Anything a
 * particular storefront needs beyond that belongs in a FalcoSense.enrich()
 * callback, not in this file.
 */
(function (w) {
    'use strict';

    /*
     * DO NOT CLOBBER AN EXISTING DEFINITION.
     *
     * A host module may already provide its own copy of this component — Everest's
     * Ahy_PlpRevamp does exactly that, defining it inline in its own search template
     * so it can add Yotpo ratings, CaliberNation pricing and seller names.
     *
     * That inline copy is parsed with the body; this file is deferred and therefore
     * runs AFTER it. A plain assignment here would silently overwrite the host's
     * version and strip those features with no error anywhere. The guard makes this
     * file a fallback: it supplies the component only when nothing else has.
     *
     * A host that wants OUR component instead simply stops defining its own.
     */
    if (w.ahySearchResults) return;

    w.ahySearchResults = 
function ahySearchResults(productsApiUrl, searchToken, searchQuery, platformStoreId, analyticsUrl, customerGeoState) {
        return {
            productsApiUrl,
            searchToken,
            searchQuery,
            platformStoreId,
            analyticsUrl,
            customerGeoState,
            results: [],
            cartLoading: {},
            wishLoading: {},
            filters: [],
            apiFacets: [],
            activeFilters: [],
            priceRange: { min: null, max: null },
            priceBuckets: [],
            activePriceMin: '',
            activePriceMax: '',
            activePriceRange: null,
            priceCollapsed: false,
            total: 0,
            page: 1,
            pageSize: 18,
            totalPages: 1,
            paginationLoading: false,
            sort: 'relevance',
            loading: true,
            /*
             * True when the last fetch FAILED — as distinct from succeeding with zero
             * products. Without this the two are indistinguishable: both leave results
             * empty, so an outage renders the same "no matches found" screen as a
             * genuinely empty result, and a failure mid-session silently leaves the
             * previous results on screen as if the filter had applied.
             */
            error: false,
            wasCorrection: false,
            correctedQuery: '',
            originalQuery: '',
            suggestedQuery: '',
            autoBrand: '',
            bypassSpell: false,

            async _refreshToken() {
                const t = await window.ahyTokenRefresh?.refresh();
                if (t) this.searchToken = t;
                return !!t;
            },

            init() {
                console.log('%c[FalcoSense PLP][search/results.phtml] build: scroll-fix-2026-08-14', 'color:#0d2f47;font-weight:bold;');
                if (this._fetched) return;
                this._fetched = true;

                // The x-data root itself now lives inside the #fs-search-shadow-host
                // shadow root (see the <template shadowrootmode> wrapper above) — a
                // plain document.getElementById() can never see into a shadow tree,
                // even one the calling script is itself part of, so every id lookup
                // below goes through this root reference instead of `document`.
                const shadowRoot = this.$root.getRootNode();

                // The plain server-rendered grid (#fs-ssr-grid, see results.phtml above
                // the x-data root) is a pre-JS fallback only — the moment Alpine boots,
                // hand off entirely to Alpine's own rendering below, whether it seeds
                // instantly from the embedded payload or falls through to a live fetch.
                shadowRoot.getElementById('fs-ssr-grid')?.remove();

                window.addEventListener('ahy-token-refreshed', e => { this.searchToken = e.detail; });
                const params = new URLSearchParams(window.location.search);
                this.page = parseInt(params.get('p') || '1', 10);
                const sortParam = params.get('sort');
                if (sortParam) this.sort = sortParam;
                const brandParam = params.get('brand');
                if (brandParam) this.autoBrand = brandParam;
                // Persisted across the full page reload that searchExact() does when the
                // shopper clicks "Do you still want to search for X" — without this, the
                // reloaded page would send the same misspelled term back to the platform,
                // which would just auto-correct it again and show the same prompt in a loop.
                if (params.get('bypass_spell') === '1') this.bypassSpell = true;

                /*
                 * Restore filters from the URL. The counterpart to _writeUrl(): without
                 * this, a shared or bookmarked filtered URL reopened as an unfiltered
                 * search. Adopted from Ahy_PlpRevamp.
                 *
                 * Note we do NOT normalise `p` into the URL here (PlpRevamp does). A
                 * rewrite on first load strips tracking params before marketing scripts
                 * elsewhere on the page have read them.
                 */
                if (params.get('price_min')) this.activePriceMin = params.get('price_min');
                if (params.get('price_max')) this.activePriceMax = params.get('price_max');
                if (this.activePriceMin || this.activePriceMax) {
                    this.activePriceRange = this.priceRangeBuckets().find(b =>
                        String(b.min) === this.activePriceMin && String(b.max) === this.activePriceMax
                    ) || null;
                }

                /*
                 * Everything not in this list is treated as a facet filter. `brand` is
                 * excluded because it is restored via autoBrand above and matched against
                 * facet options in buildFilters() — adding it here too would double it.
                 */
                const NON_FILTER_PARAMS = [
                    'q', 'p', 'sort', 'price_min', 'price_max', 'brand', 'bypass_spell',
                    '___store', '___from_store', 'SID', 'sid',
                    'gclid', 'fbclid', 'msclkid', 'dclid', 'ttclid', 'igshid', 'mc_cid', 'mc_eid',
                    'product_list_order', 'product_list_dir', 'product_list_limit', 'product_list_mode'
                ];
                params.forEach((value, key) => {
                    if (NON_FILTER_PARAMS.includes(key) || key.startsWith('utm_')) return;
                    value.split('|').forEach(v => {
                        if (v) this.activeFilters.push({ key, label: key, value: v });
                    });
                });

                // Seed from the server-rendered payload instead of re-fetching, but only
                // for the exact canonical view it was rendered for (page 1, no sort/brand
                // param, no bypass_spell) — any other URL state falls straight through to
                // the normal fetch() below, completely unchanged.
                const isCanonical = this.page === 1 && !sortParam && !brandParam && !this.bypassSpell;
                const payloadEl = isCanonical ? shadowRoot.getElementById('fs-ssr-payload') : null;
                if (payloadEl) {
                    try {
                        const seed = JSON.parse(payloadEl.textContent);
                        if (seed && seed.success) {
                            this.results = seed.data || [];
                            this.total = (seed.pagination && seed.pagination.total) || 0;
                            this.pageSize = (seed.pagination && seed.pagination.per_page) || this.pageSize;
                            this.totalPages = Math.max(1, Math.ceil(this.total / this.pageSize));
                            this.apiFacets = seed.facets || [];
                            this.buildFilters();
                            this.loading = false;
                            return;
                        }
                    } catch (e) {
                        console.error('[SmartSearch] SSR payload parse error, falling back to fetch()', e);
                    }
                }

                const startFetch = () => {
                    window.ahyTokenRefresh?.get().then(t => {
                        if (t) this.searchToken = t;
                        this.fetch();
                    });
                };
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', startFetch, { once: true });
                } else {
                    startFetch();
                }
            },

            async fetch() {
                this.loading = true;
                try {
                    const t0 = performance.now();
                    const url = new URL(this.productsApiUrl);
                    url.searchParams.set('search_token', this.searchToken);
                    url.searchParams.set('q', this.searchQuery);
                    url.searchParams.set('page', this.page);
                    url.searchParams.set('per_page', this.pageSize);
                    url.searchParams.set('include_variants', '1');
                    // platform_store_id intentionally omitted — getPlatformStoreId()'s
                    // position-based calculation is wrong for single-store-view sites
                    // (always resolves to 1). Omitting it lets the backend fall back to
                    // the store already correctly configured on the API key itself,
                    // same as the header search (search-form.phtml) already does.
                    if (this.customerGeoState) url.searchParams.set('geo_state', this.customerGeoState);
                    if (this.bypassSpell) url.searchParams.set('bypass_spell', '1');

                    const filterMap = {};
                    this.activeFilters.forEach(f => {
                        if (!filterMap[f.key]) filterMap[f.key] = [];
                        filterMap[f.key].push(f.value);
                    });
                    Object.entries(filterMap).forEach(([key, vals]) => url.searchParams.set(key, vals.join('\x1F')));
                    if (this.activePriceMin !== '') url.searchParams.set('price_min', this.activePriceMin);
                    if (this.activePriceMax !== '') url.searchParams.set('price_max', this.activePriceMax);

                    let resp = await fetch(url.toString(), { credentials: 'include', cache: 'no-store' });
                    if (resp.status === 401) {
                        await this._refreshToken();
                        url.searchParams.set('search_token', this.searchToken);
                        resp = await fetch(url.toString(), { credentials: 'include', cache: 'no-store' });
                    }
                    const data = await resp.json();

                    if (data.success) {
                        this.error = false;
                        this.results = data.data || [];
                        this.total = (data.pagination && data.pagination.total) || 0;
                        this.pageSize = (data.pagination && data.pagination.per_page) || 50;
                        this.totalPages = Math.max(1, Math.ceil(this.total / this.pageSize));
                        this.apiFacets = data.facets || [];
                        if (data.was_corrected) {
                            this.wasCorrection = true;
                            this.correctedQuery = data.corrected_query;
                            this.originalQuery  = data.original_query || this.searchQuery;
                        } else if (!this.wasCorrection) {
                            this.wasCorrection  = false;
                            this.correctedQuery = '';
                            this.originalQuery  = '';
                        }
                        this.suggestedQuery = data.suggested_query || '';
                        this.buildFilters();
                        this.trackSearch(this.searchQuery, this.total, Math.round(performance.now() - t0));

                        /*
                         * Host extension point. A storefront layers its own per-product
                         * data on here — review ratings, member pricing, seller names —
                         * by calling FalcoSense.enrich(). Everest currently does this by
                         * maintaining a full copy of this component; the hook replaces
                         * that. No enrichers registered means no extra requests.
                         */
                        if (window.FalcoSense) window.FalcoSense._runEnrichers(this.results, this);
                    } else {
                        this.error = true;
                        console.error('[SmartSearch] Search API error:', data);
                    }
                } catch (e) {
                    this.error = true;
                    console.error('[SmartSearch] Search fetch error', e);
                }
                this.loading = false;
                if (this.results.length === 0 && !this.activeFilters.length && this.activePriceMin === '' && this.activePriceMax === '') {
                    window.dispatchEvent(new CustomEvent('ahy-modal-search', { detail: { query: this.searchQuery } }));
                }
            },

            buildFilters() {
                if (this.apiFacets.length === 0) return;
                const priceFacet = this.apiFacets.find(f => f.key === 'price');
                if (priceFacet) {
                    this.priceRange = { min: priceFacet.min ?? null, max: priceFacet.max ?? null };
                    this.priceBuckets = this.priceRangeBuckets();
                }
                const prevState = {};
                this.filters.forEach(f => { prevState[f.key] = { collapsed: f.collapsed, showAll: f.showAll }; });
                this.filters = this.apiFacets
                    .filter(f => f.key !== 'price' && f.key !== 'category')
                    .map(f => ({
                        key: f.key,
                        label: f.key === 'brand' ? 'Shop By Brand' : f.label,
                        collapsed: prevState[f.key]?.collapsed ?? false,
                        showAll:   prevState[f.key]?.showAll ?? false,
                        options: (f.options || []).map(o => ({ value: o.value, count: o.count })).sort((a, b) => {
                            const aA = this.activeFilters.some(af => af.key === f.key && af.value === a.value) ? -1 : 1;
                            const bA = this.activeFilters.some(af => af.key === f.key && af.value === b.value) ? -1 : 1;
                            return aA - bA;
                        }),
                    }));
                if (this.autoBrand && !this.activeFilters.some(f => f.key === 'brand')) {
                    const brandFilter = this.filters.find(f => f.key === 'brand');
                    if (brandFilter) {
                        const match = brandFilter.options.find(o => o.value.toLowerCase() === this.autoBrand.toLowerCase());
                        if (match) {
                            this.activeFilters.push({ key: 'brand', label: 'Shop By Brand', value: match.value });
                            this.autoBrand = '';
                            this.page = 1;
                            this.fetch();
                        }
                    }
                }
            },

            sortedResults() {
                if (!Array.isArray(this.results)) return [];
                let res = this.results;
                if (this.activePriceMin !== '' || this.activePriceMax !== '') {
                    const lo = this.activePriceMin !== '' ? parseFloat(this.activePriceMin) : 0;
                    const hi = this.activePriceMax !== '' ? parseFloat(this.activePriceMax) : Infinity;
                    res = res.filter(p => {
                        const price = parseFloat(
                            (p.special_price && parseFloat(p.special_price) < parseFloat(p.price))
                                ? p.special_price : (p.price || 0)
                        );
                        return price >= lo && (hi === Infinity || price <= hi);
                    });
                }
                if (this.sort === 'relevance') return res;
                return [...res].sort((a, b) => {
                    const getPrice = p => {
                        const base = parseFloat(p.price) || 0;
                        const sp = parseFloat(p.special_price) || 0;
                        return (sp > 0 && sp < base) ? sp : base;
                    };
                    const diff = getPrice(a) - getPrice(b);
                    return this.sort === 'price_asc' ? diff : -diff;
                });
            },

            async trackSearch(query, resultCount, responseTimeMs) {
                if (!query || !this.analyticsUrl) return;
                try {
                    await fetch(this.analyticsUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ search_token: this.searchToken, query, result_count: resultCount, response_time_ms: responseTimeMs, page: this.page }),
                        credentials: 'include',
                    });
                } catch (e) { }
            },

            trackEvent(payload) {
                if (!this.analyticsUrl) return;
                const eventsUrl = this.analyticsUrl.replace('/api/v1/analytics/search', '/api/v1/events');
                try {
                    navigator.sendBeacon(eventsUrl, new Blob(
                        [JSON.stringify({ search_token: this.searchToken, ...payload })],
                        { type: 'application/json' }
                    ));
                } catch (e) { }
            },

            trackClick(product, position) {
                this.trackEvent({ event_type: 'search_click', product_id: product.product_id, product_name: product.name, product_sku: product.sku, product_price: product.price, query: this.searchQuery, position });
            },

            searchExact(q, bypassSpell = false) {
                const params = new URLSearchParams(window.location.search);
                params.set('q', q);
                // bypassSpell is only true for the "Do you still want to search for X"
                // link (the shopper's own original, uncorrected term) — it tells the
                // platform to skip auto-correction entirely instead of just re-running
                // the same correction on reload and showing this same prompt again.
                if (bypassSpell) { params.set('bypass_spell', '1'); } else { params.delete('bypass_spell'); }
                window.location.search = params.toString();
            },

            toggleFilter(key, label, value) {
                const idx = this.activeFilters.findIndex(f => f.key === key && f.value === value);
                if (idx >= 0) this.activeFilters.splice(idx, 1);
                else this.activeFilters.push({ key, label, value });
                this.page = 1;
                this._writeUrl();
                this._scrollToResults();
                this.fetch();
            },

            removeFilter(key, value) {
                this.activeFilters = this.activeFilters.filter(f => !(f.key === key && f.value === value));
                this.page = 1;
                this._writeUrl();
                this._scrollToResults();
                this.fetch();
            },

            applyPriceFilter() { this.page = 1; this._writeUrl(); this._scrollToResults(); this.fetch(); },
            clearPriceFilter() { this.activePriceMin = ''; this.activePriceMax = ''; this.activePriceRange = null; this.page = 1; this._writeUrl(); this._scrollToResults(); this.fetch(); },
            clearAllFilters() { this.activeFilters = []; this.activePriceMin = ''; this.activePriceMax = ''; this.activePriceRange = null; this.page = 1; this._writeUrl(); this._scrollToResults(); this.fetch(); },

            priceRangeBuckets() {
                if (this.priceRange.min === null) return [];
                const rangeMax = this.priceRange.max !== null ? this.priceRange.max : Infinity;
                const all = [
                    { label: 'Under $50', min: '', max: 50 },
                    { label: '$50 – $100', min: 50, max: 100 },
                    { label: '$100 – $250', min: 100, max: 250 },
                    { label: '$250 – $500', min: 250, max: 500 },
                    { label: '$500 – $1,000', min: 500, max: 1000 },
                    { label: 'Over $1,000', min: 1000, max: '' },
                ];
                return all.filter(b => {
                    const lo = b.min === '' ? 0 : b.min;
                    const hi = b.max === '' ? Infinity : b.max;
                    return lo < rangeMax && hi > this.priceRange.min;
                });
            },

            togglePriceRange(bucket) {
                if (this.activePriceRange && this.activePriceRange.label === bucket.label) {
                    this.activePriceRange = null;
                    this.activePriceMin = '';
                    this.activePriceMax = '';
                } else {
                    this.activePriceRange = bucket;
                    this.activePriceMin = bucket.min === '' ? '' : String(bucket.min);
                    this.activePriceMax = bucket.max === '' ? '' : String(bucket.max);
                }
                this.applyPriceFilter();
            },

                isFilterActive(key, value) { return this.activeFilters.some(f => f.key === key && f.value === value); },
            
              // Drives the scroll animation ourselves via requestAnimationFrame
              // instead of window.scrollTo({behavior:'smooth'}). Safari's native
              // smooth-scroll can get interrupted/clipped when the page's
              // scrollable height changes mid-animation — which happens here
              // since fetch() briefly hides the grid a frame after we start
              // scrolling — leaving the scroll stuck partway or not moving at
              // all. Re-asserting the target position every frame (rather than
              // handing off to the browser once) behaves identically across
              // Safari, Chrome and Firefox, so this also replaces the old
              // scrollBehavior-feature-detection fallback below.
              _animateScrollTo(targetY, duration = 500) {
                if (this._scrollAnimFrame) cancelAnimationFrame(this._scrollAnimFrame);
                const startY = window.scrollY;
                const distance = targetY - startY;
                if (Math.abs(distance) < 2) return;
                const startTime = performance.now();
                const easeInOutQuad = t => (t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2);
                const step = (now) => {
                    const progress = Math.min((now - startTime) / duration, 1);
                    window.scrollTo(0, startY + distance * easeInOutQuad(progress));
                    this._scrollAnimFrame = progress < 1 ? requestAnimationFrame(step) : null;
                };
                this._scrollAnimFrame = requestAnimationFrame(step);
              },

              _scrollToResults() {
               const el = this.$root.getRootNode().getElementById('search-results-layout');
                if (!el) return;
                const top = el.getBoundingClientRect().top + window.pageYOffset + 0;
                console.log('[FalcoSense PLP][search/results.phtml] _scrollToResults: from', window.scrollY, 'to', top);
                this._animateScrollTo(top);
            },

            goToPage(p) {
                this.page = p;
                /* PlpRevamp's copy scrolls BEFORE fetch(), which reintroduces the bug
                   described below. Keeping our ordering; only the URL write is shared. */
                this._writeUrl();
                // fetch() flips the main `loading` flag, which hides
                // #search-results-layout and collapses the page height out from
                // under the current (possibly deep) scroll position — scrolling
                // to the filters top *before* or *during* that collapse just gets
                // clamped back up near scrollY 0. The paginationLoading overlay
                // covers the collapse/re-render visually, and we scroll only
                // after fetch() resolves and the real layout has settled, so the
                // target position is stable when we scroll to it.
                this.paginationLoading = true;
                this.fetch().then(() => {
                    this.paginationLoading = false;
                    this.$nextTick(() => this._scrollToResults());
                });
            },
            /*
             * Mirror the current view into the URL so it can be bookmarked, shared,
             * and restored by the back button. Adopted from Ahy_PlpRevamp, which
             * added it on top of its copy of this component; without it, sorting and
             * filtering left the address bar stale.
             *
             * replaceState (not pushState): a filter click is a refinement of the
             * same result set, not a new history entry to step back through.
             */
            _writeUrl() {
                const params = new URLSearchParams();
                params.set('q', this.searchQuery);
                if (this.bypassSpell) params.set('bypass_spell', '1');
                params.set('p', this.page);
                if (this.sort !== 'relevance') params.set('sort', this.sort);
                if (this.activePriceMin !== '') params.set('price_min', this.activePriceMin);
                if (this.activePriceMax !== '') params.set('price_max', this.activePriceMax);
                const filterMap = {};
                this.activeFilters.forEach(f => {
                    if (!filterMap[f.key]) filterMap[f.key] = [];
                    filterMap[f.key].push(f.value);
                });
                Object.entries(filterMap).forEach(([key, vals]) => params.set(key, vals.join('|')));
                const qs = params.toString();
                window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
            },

            setSort(val) { this.sort = val; this._writeUrl(); },
            sortLabel() {
                return { relevance: 'Relevance', price_asc: 'Low to High', price_desc: 'High to Low' }[this.sort] || 'Relevance';
            },
            /* Was hardcoded to Everest's two seller names, so no other storefront
               could ever show a free-shipping badge. Now supplied per store — see
               web/js/plp/runtime.js. */
            isFreeShipping(product) {
                var cfg = (window.FalcoSense && window.FalcoSense.config) || {};
                return (cfg.freeShippingSellers || []).includes(product.brand || cfg.defaultSeller || '');
            },
            cleanName(name) {
                if (!name) return '';
                const d = document.createElement('textarea');
                d.innerHTML = name;
                return d.value;
            },

            /* Was the Everest logo, which every other storefront would have shown
               for missing images. Empty default renders nothing instead. */
            FALLBACK_IMG: ((window.FalcoSense && window.FalcoSense.config.fallbackImage) || ''),
            imgUrl(image) {
                if (!image || image.includes('no_selection')) return this.FALLBACK_IMG;
                if (image.includes('falcosense/800x800')) return image;
                let path = image;
                if (image.startsWith('http')) {
                    const m = image.match(/\/catalog\/product\/(.+)$/);
                    if (!m) return image;
                    path = m[1];
                } else {
                    path = path.replace(/^\/+/, '');
                }
                if (!path) return this.FALLBACK_IMG;
                const filename = path.split('/').pop();
                if (!filename) return this.FALLBACK_IMG;
                const c1 = filename[0], c2 = filename[1] || c1;
                var cdn = (window.FalcoSense && window.FalcoSense.config.cdnBase) || '';
                return cdn + '/media/falcosense/800x800/' + c1 + '/' + c2 + '/' + filename;
            },
            /* Compares the RESOLVED url. Taking the raw value meant a product whose
               image resolves to the fallback was not detected as such. */
            isFallbackImg(image) {
                return this.imgUrl(image) === this.FALLBACK_IMG;
            },
            handleImgError(event) {
                const el = event.target;
                if (el.dataset.imgFailed) {
                    el.src = this.FALLBACK_IMG;
                    el.style.opacity = '0.45';
                    return;
                }
                el.dataset.imgFailed = '1';
                const orig = el.getAttribute('data-original-src');
                if (!orig || orig.includes('no_selection')) {
                    el.src = this.FALLBACK_IMG;
                    el.style.opacity = '0.45';
                    return;
                }
                el.src = orig.startsWith('http') ? orig : '/media/catalog/product' + orig;
            },

            async addSimpleToCart(product) {
                const pid = product.product_id;
                this.cartLoading = { ...this.cartLoading, [pid]: true };
                try {
                    const params = new URLSearchParams({ product: pid, qty: 1, form_key: hyva.getFormKey() });
                    const resp = await fetch('<?= $addToCartUrl ?>', {
                        method: 'POST',
                        body: params,
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (resp.ok || resp.redirected) {
                        window.dispatchEvent(new CustomEvent('reload-customer-section-data'));
                        window.dispatchEvent(new CustomEvent('toggle-cart'));
                        typeof window.dispatchMessages !== 'undefined' && window.dispatchMessages([{ type: 'success', text: 'Product added to cart.' }], 3000);
                        this.trackEvent({ event_type: 'add_to_cart', product_id: pid, product_name: product.name, product_sku: product.sku, product_price: product.price, quantity: 1, query: this.searchQuery });
                    }
                } catch (e) {
                    console.error('[SmartSearch] Add to cart failed', e);
                } finally {
                    this.cartLoading = { ...this.cartLoading, [pid]: false };
                }
            },

            async addToWishlist(product) {
                const pid = product.product_id;
                this.wishLoading = { ...this.wishLoading, [pid]: true };
                try {
                    const resp = await window.fetch(window.BASE_URL + 'wishlist/index/add/', {
                        method: 'POST',
                        headers: { 'content-type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: 'form_key=' + hyva.getFormKey() + '&product=' + pid + '&uenc=' + hyva.getUenc(),
                        credentials: 'include',
                    });
                    if (resp.redirected) { window.location.href = resp.url; return; }
                    if (resp.ok) {
                        const data = await resp.json().catch(() => null);
                        const msg = data && data.success ? 'Product added to Wish List.' : (data && data.error_message) || 'Could not add to wishlist.';
                        const type = data && data.success ? 'success' : 'error';
                        typeof window.dispatchMessages !== 'undefined' && window.dispatchMessages([{ type, text: msg }], 5000);
                        window.dispatchEvent(new CustomEvent('reload-customer-section-data'));
                        this.trackEvent({ event_type: 'wishlist_add', product_id: pid, product_name: product.name, product_sku: product.sku, product_price: product.price, query: this.searchQuery });
                    }
                } catch (e) { console.error('[SmartSearch] wishlist error', e); }
                finally { this.wishLoading = { ...this.wishLoading, [pid]: false }; }
            },
        };
    }
})(window);
