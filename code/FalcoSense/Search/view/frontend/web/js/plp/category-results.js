/**
 * FalcoSense — category results component.
 * ---------------------------------------------------------------------------
 * Alpine component behind the category PLP. Mounted as
 *
 *     x-data="ahyCategoryResults(apiUrl, token, category, storeId, categoryId)"
 *
 * See web/js/plp/search-results.js for why these components are files rather
 * than inline <script> blocks, and how this version was reconciled against
 * Ahy_PlpRevamp's fork. The same decisions apply here:
 *   - from PlpRevamp: _writeUrl() and its call sites, URL state restore in init().
 *   - kept from FalcoSense: SSR payload seeding, _scrollToResults(), the
 *     Array.isArray guard in sortedResults().
 *
 * Unlike the search component this one renders in the LIGHT DOM on purpose —
 * the category page is the primary crawlable listing, and content inside a
 * shadow root is invisible to crawlers that do not run JS.
 *
 * Store-specific values (seller names, fallback image, CDN origin) come from
 * FalcoSense.config — see web/js/plp/runtime.js.
 */
(function (w) {
    'use strict';

    /*
     * DO NOT CLOBBER AN EXISTING DEFINITION.
     *
     * A host module may already provide its own copy of this component — Everest's
     * Ahy_PlpRevamp does exactly that, defining it inline in its own category template
     * so it can add Yotpo ratings, CaliberNation pricing and seller names.
     *
     * That inline copy is parsed with the body; this file is deferred and therefore
     * runs AFTER it. A plain assignment here would silently overwrite the host's
     * version and strip those features with no error anywhere. The guard makes this
     * file a fallback: it supplies the component only when nothing else has.
     *
     * A host that wants OUR component instead simply stops defining its own.
     */
    if (w.ahyCategoryResults) return;

    w.ahyCategoryResults = 
function ahyCategoryResults(productsApiUrl, searchToken, categoryName, platformStoreId, categoryId) {
    return {
        productsApiUrl,
        searchToken,
        categoryName,
        platformStoreId,
        categoryId,
        results: [],
        cartLoading: {},
        wishLoading: {},
        filters: [],
        apiFacets: [],
        activeFilters: [],
        priceRange: { min: null, max: null },
        activePriceMin: '',
        activePriceMax: '',
        activePriceRange: null,
        priceCollapsed: false,
        total: 0,
        page: 1,
        pageSize: 18,
        totalPages: 1,
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
        paginationLoading: false,

        async _refreshToken() {
            const t = await window.ahyTokenRefresh?.refresh();
            if (t) this.searchToken = t;
            return !!t;
        },

        init() {
            // The plain server-rendered grid (#fs-ssr-grid, see results.phtml above
            // this x-data root) is a pre-JS fallback only — the moment Alpine boots,
            // hand off entirely to Alpine's own rendering below, whether it seeds
            // instantly from the embedded payload or falls through to a live fetch.
            document.getElementById('fs-ssr-grid')?.remove();

            window.addEventListener('ahy-token-refreshed', e => { this.searchToken = e.detail; });
            const params = new URLSearchParams(window.location.search);
            this.page = parseInt(params.get('p') || '1', 10);
            const sortParam = params.get('sort');
            if (sortParam) this.sort = sortParam;

            /*
             * Restore filters from the URL — counterpart to _writeUrl(). Without it a
             * shared or bookmarked filtered category URL reopened unfiltered.
             * Adopted from Ahy_PlpRevamp.
             */
            if (params.get('price_min')) this.activePriceMin = params.get('price_min');
            if (params.get('price_max')) this.activePriceMax = params.get('price_max');
            if (this.activePriceMin || this.activePriceMax) {
                this.activePriceRange = this.priceRangeBuckets().find(b =>
                    String(b.min) === this.activePriceMin && String(b.max) === this.activePriceMax
                ) || null;
            }

            /* Anything not listed here is treated as a facet filter. */
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

            // Unlike search, this is seeded for EVERY page/sort — the server built
            // this exact view (see PageContext::buildCategoryQuery), not just a
            // canonical default — so there's no "is this the default view" gate here.
            const payloadEl = document.getElementById('fs-ssr-payload');
            if (payloadEl) {
                try {
                    const seed = JSON.parse(payloadEl.textContent);
                    if (seed && seed.success) {
                        this.results    = seed.data || [];
                        this.total      = (seed.pagination && seed.pagination.total) || 0;
                        this.pageSize   = (seed.pagination && seed.pagination.per_page) || this.pageSize;
                        this.totalPages = Math.max(1, Math.ceil(this.total / this.pageSize));
                        this.apiFacets  = seed.facets || [];
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
                const url = new URL(this.productsApiUrl);
                url.searchParams.set('search_token', this.searchToken);
                url.searchParams.set('category', this.categoryName);
                // Two categories can share the same name (e.g. a "Featured Products"
                // subcategory exists under both "Boating Gear" and "Hunting Gear"), so
                // the name-only filter above matches every same-named category's
                // products combined. Sending category_ids scopes the match to this
                // exact category — the API prefers it over the name filter when both
                // are present (see OpenSearchService's category_ids-first handling).
                if (this.categoryId) url.searchParams.set('category_ids', this.categoryId);
                url.searchParams.set('page', this.page);
                url.searchParams.set('per_page', this.pageSize);
                // platform_store_id intentionally omitted — getPlatformStoreId()'s
                // position-based calculation is wrong for single-store-view sites
                // (always resolves to 1). Omitting it lets the backend fall back to
                // the store already correctly configured on the API key itself,
                // same as the header search (search-form.phtml) and search/results.phtml.

                const filterMap = {};
                this.activeFilters.forEach(f => {
                    if (f.key === 'category') return;
                    if (!filterMap[f.key]) filterMap[f.key] = [];
                    filterMap[f.key].push(f.value);
                });
                Object.entries(filterMap).forEach(([key, vals]) => url.searchParams.set(key, vals.join('\x1F')));
                if (this.activePriceMin !== '') url.searchParams.set('price_min', this.activePriceMin);
                if (this.activePriceMax !== '') url.searchParams.set('price_max', this.activePriceMax);
                if (this.sort !== 'relevance') url.searchParams.set('sort', this.sort);

                let resp = await fetch(url.toString(), {credentials: 'include', cache: 'no-store'});
                if (resp.status === 401) {
                    await this._refreshToken();
                    url.searchParams.set('search_token', this.searchToken);
                    resp = await fetch(url.toString(), {credentials: 'include', cache: 'no-store'});
                }
                const data = await resp.json();

                if (data.success) {
                    this.error = false;
                    this.results    = data.data || [];
                    console.log('[Category] product.image samples:', this.results.slice(0,3).map(p => ({sku: p.sku, image: p.image})));
                    this.total      = (data.pagination && data.pagination.total) || 0;
                    this.pageSize   = (data.pagination && data.pagination.per_page) || 18;
                    this.totalPages = Math.max(1, Math.ceil(this.total / this.pageSize));
                    this.apiFacets  = data.facets || [];
                    this.buildFilters();

                    /* Host extension point — see FalcoSense.enrich() in runtime.js. */
                    if (window.FalcoSense) window.FalcoSense._runEnrichers(this.results, this);
                } else {
                    this.error = true;
                        console.error('[SmartSearch] Category API error:', data);
                }
            } catch(e) {
                this.error = true;
                    console.error('[SmartSearch] Category fetch error', e);
            }
            this.loading = false;
        },

        buildFilters() {
            if (this.apiFacets.length === 0) return;
            const priceFacet = this.apiFacets.find(f => f.key === 'price');
            if (priceFacet) {
                this.priceRange = { min: priceFacet.min ?? null, max: priceFacet.max ?? null };
            }
            const prevState = {};
            this.filters.forEach(f => { prevState[f.key] = { collapsed: f.collapsed, showAll: f.showAll }; });
            this.filters = this.apiFacets
                .filter(f => f.key !== 'price' && f.key !== 'category')
                .map(f => ({
                    key:       f.key,
                    label:     f.key === 'brand' ? 'Shop By Brand' : f.label,
                    collapsed: prevState[f.key]?.collapsed ?? false,
                    showAll:   prevState[f.key]?.showAll ?? false,
                    options:   (f.options || []).map(o => ({ value: o.value, count: o.count })).sort((a, b) => {
                        const aA = this.activeFilters.some(af => af.key === f.key && af.value === a.value) ? -1 : 1;
                        const bA = this.activeFilters.some(af => af.key === f.key && af.value === b.value) ? -1 : 1;
                        return aA - bA;
                    }),
                }));
        },

        sortedResults() {
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
                    const sp   = parseFloat(p.special_price) || 0;
                    return (sp > 0 && sp < base) ? sp : base;
                };
                const diff = getPrice(a) - getPrice(b);
                return this.sort === 'price_asc' ? diff : -diff;
            });
        },

        _scrollToResults() {
            const el = document.querySelector('.column.main');
            if (!el) return;
            const y = Math.max(0, el.getBoundingClientRect().top + window.pageYOffset);
            window.scrollTo({ top: y, behavior: 'smooth' });
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
            const all = [
                { label: 'Under $50',     min: '',   max: 50   },
                { label: '$50 – $100',    min: 50,   max: 100  },
                { label: '$100 – $250',   min: 100,  max: 250  },
                { label: '$250 – $500',   min: 250,  max: 500  },
                { label: '$500 – $1,000', min: 500,  max: 1000 },
                { label: 'Over $1,000',   min: 1000, max: ''   },
            ];
            return all.filter(b => {
                const lo = b.min === '' ? 0 : b.min;
                const hi = b.max === '' ? Infinity : b.max;
                return lo < this.priceRange.max && hi > this.priceRange.min;
            });
        },

        togglePriceRange(bucket) {
            if (this.activePriceRange && this.activePriceRange.label === bucket.label) {
                this.activePriceRange = null; this.activePriceMin = ''; this.activePriceMax = '';
            } else {
                this.activePriceRange = bucket;
                this.activePriceMin = bucket.min === '' ? '' : String(bucket.min);
                this.activePriceMax = bucket.max === '' ? '' : String(bucket.max);
            }
            this.applyPriceFilter();
        },

        isFilterActive(key, value) { return this.activeFilters.some(f => f.key === key && f.value === value); },

        goToPage(p) { this.page = p; this._writeUrl(); this.paginationLoading = true; this.fetch().then(() => { this.paginationLoading = false; this.$nextTick(() => this._scrollToResults()); }); },
        /*
         * Mirror the current view into the URL — bookmarkable, shareable, survives
         * the back button. Adopted from Ahy_PlpRevamp. No `q` here (a category page
         * has no query term); otherwise identical to the search component's copy.
         */
        _writeUrl() {
            const params = new URLSearchParams();
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

        setSort(val) {
            this.sort = val; this.page = 1; this._writeUrl(); this.fetch();
        },
        sortLabel() {
            return { relevance: 'Relevance', price_asc: 'Price: Low to high', price_desc: 'Price: High to low' }[this.sort] || 'Relevance';
        },

        /* Was hardcoded to Everest's seller names — see web/js/plp/runtime.js. */
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

        /* Was the Everest logo on the Everest CDN. Empty default renders nothing. */
        FALLBACK_IMG: ((window.FalcoSense && window.FalcoSense.config.fallbackImage) || ''),
        isFallbackImg(image) {
            return this.imgUrl(image) === this.FALLBACK_IMG;
        },
        imgUrl(image) {
            if (!image || image.includes('no_selection')) return this.FALLBACK_IMG;
            const cdn = (window.FalcoSense && window.FalcoSense.config.cdnBase) || '';
            const falcosenseBase = cdn + '/media/falcosense/800x800';
            if (image.includes('/falcosense/')) {
                const fm = image.match(/\/falcosense\/[^/]+\/(?:.*\/)?([^/]+\/[^/]+\/[^/]+)$/);
                return fm ? falcosenseBase + '/' + fm[1] : image;
            }
            let path = image.startsWith('http') ? image.replace(/^https?:\/\/[^/]+/, '') : image;
            path = path.replace(/\/cache\/[^/]+\//, '/');
            const m = path.match(/\/catalog\/product\/(.+)$/);
            if (m) return falcosenseBase + '/' + m[1];
            const filename = path.split('/').pop();
            if (!filename) return this.FALLBACK_IMG;
            if (filename.length >= 2) {
                return falcosenseBase + '/' + filename[0] + '/' + filename[1] + '/' + filename;
            }
            return falcosenseBase + '/' + path.replace(/^\/+/, '');
        },
        handleImgError(event) {
            const el = event.target;
            if (el.dataset.imgFailed) return;
            el.dataset.imgFailed = '1';
            el.src = this.FALLBACK_IMG;
            el.style.opacity = '0.45';
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
                    typeof window.dispatchMessages !== 'undefined' && window.dispatchMessages([{type: 'success', text: 'Product added to cart.'}], 3000);
                }
            } catch(e) {
                console.error('[SmartSearch] Add to cart failed', e);
            } finally {
                this.cartLoading = { ...this.cartLoading, [pid]: false };
            }
        },

        async addToWishlist(productId) {
            const pid = productId;
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
                }
            } catch(e) { console.error('[SmartSearch] wishlist error', e); }
            finally { this.wishLoading = { ...this.wishLoading, [pid]: false }; }
        },
    };
}
})(window);
