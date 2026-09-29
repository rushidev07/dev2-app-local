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
    /*
     * Fetching, filters, facets, paging, sorting and image handling come from
     * FalcoSense.listingCore(). Only what is genuinely the category grid's stays
     * below: reading and writing its own URL state, the free-shipping badge and
     * the seller-name cleanup.
     *
     * clientPriceFilter preserves this grid's existing behaviour of filtering by
     * price in the browser as well as at the platform — see the option's note in
     * listing-engine.js for why that is still here.
     */
    return Object.assign(w.FalcoSense.listingCore({ pageSize: 18, clientPriceFilter: true }), {
        productsApiUrl,
        searchToken,
        categoryName,
        platformStoreId,
        categoryId,

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




        _scrollToResults() {
            const el = document.querySelector('.column.main');
            if (!el) return;
            const y = Math.max(0, el.getBoundingClientRect().top + window.pageYOffset);
            window.scrollTo({ top: y, behavior: 'smooth' });
        },







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

        /* ---- hooks into the shared engine --------------------------------- */

        /** The category grid identifies its listing by category, not by query. */
        _applyQueryParams(url) {
            url.searchParams.set('category', this.categoryName);
            if (this.categoryId) url.searchParams.set('category_ids', this.categoryId);
        },
    });
}
})(window);
