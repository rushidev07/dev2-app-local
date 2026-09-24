/**
 * FalcoSense — header search overlay component.
 * ---------------------------------------------------------------------------
 * Mounted as x-data="ahyModalSearch(apiUrl, token, addToCartUrl)" by
 * html/header/search-form.phtml, which the theme renders once per header
 * variant.
 *
 * WHY THIS IS A FILE
 * ------------------
 * Same reason as search-results.js: inline in the template, a storefront could
 * not replace the overlay's markup without losing its behaviour. This is the
 * third copy of FalcoSense's search engine to be pulled out of a template.
 *
 * STILL A FORK — MERGE PENDING
 * ----------------------------
 * This is currently a VERBATIM extraction, not a merge. It duplicates ~22
 * methods that already exist in search-results.js (fetch, buildFilters,
 * toggleFilter, goToPage, setSort, the price filters, sortedResults...), and
 * the two have already drifted: this copy lacks _writeUrl(), trackSearch(),
 * trackClick(), trackEvent() and _refreshToken().
 *
 * The remaining work is to compose it from the shared engine:
 *
 *     w.ahyModalSearch = function (apiUrl, token, addToCartUrl) {
 *         return Object.assign(
 *             w.ahySearchResults(apiUrl, token, '', 0, '', ''),
 *             { ...the 14 genuinely modal-only members... }
 *         );
 *     };
 *
 * The 14 that must stay: openModal, closeModal, _scrollModalToTop,
 * fetchPopularProducts, fetchPopularSearches, addToWishlistModal,
 * _findVisibleById, and the popular-products carousel (_popCardWidth,
 * _popDragStart/Move/End, _popScrollNext/Prev/Update).
 *
 * Differences to reconcile during that merge, each a real behavioural change:
 *   - init(): the engine seeds from an SSR payload and looks up elements through
 *     a shadow root. The overlay has neither.
 *   - pageSize: 12 here, 50 in the engine.
 *   - searchExact(): the engine reloads the page; this one refetches in place.
 *   - fetch(): the engine also sends geo_state and bypass_spell.
 *
 * Extracting first and merging second is deliberate — it turns template surgery
 * into a file-to-file diff.
 */
(function (w) {
    'use strict';

    /* Do not clobber a host-supplied component — see search-results.js. */
    if (w.ahyModalSearch) return;

    w.ahyModalSearch = 
function ahyModalSearch(productsApiUrl, searchToken, addToCartUrl) {
    return {
        productsApiUrl,
        searchToken,
        addToCartUrl,
        results: [],
        popularProducts: [],
        popularSearches: [],
        isMobile: window.innerWidth < 768,
        popularScrollPos: 0,
        popularScrollMax: 0,
        _popDragging: false,
        _popDragStartX: 0,
        _popDragScrollLeft: 0,
        _popDragMoved: false,
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
        pageSize: 12,
        totalPages: 1,
        sort: 'relevance',
        loading: false,
        error: false,
        paginationLoading: false,
        cartLoading: {},
        wishLoading: {},
        mobileFiltersOpen: false,
        mobileSortOpen: false,
        wasCorrection: false,
        correctedQuery: '',
        originalQuery: '',
        suggestedQuery: '',
        searchQuery: '',
        _pageContent: [],

        init() {
            if (window._ahyModalInited) return;
            window._ahyModalInited = true;
            this.$watch('mobileFiltersOpen', val => { document.body.style.overflow = val ? 'hidden' : ''; });
            window.ahyTokenRefresh.get().then(t => { if (t) this.searchToken = t; });
            window.addEventListener('ahy-token-refreshed', e => { this.searchToken = e.detail; });

            this._pageContent = [
                document.querySelector('main'),
                document.querySelector('.page-main'),
                document.querySelector('#maincontent'),
            ].filter((el, i, arr) => el && arr.indexOf(el) === i);

            const wrapper = this.$el.closest('.columns') || this.$el;
            const header  = document.querySelector('header') || document.querySelector('.page-header');
            if (header && header.parentNode) {
                header.parentNode.insertBefore(wrapper, header.nextSibling);
            }

            window.addEventListener('ahy-modal-search', (e) => {
                const q = e.detail.query;
                if (q !== this.searchQuery) { this.page = 1; this.sort = 'relevance'; this.activeFilters = []; this.activePriceMin = ''; this.activePriceMax = ''; this.activePriceRange = null; this.wasCorrection = false; this.correctedQuery = ''; }
                this.searchQuery = q;
                this.openModal();
                this.fetch();
            });
            window.addEventListener('ahy-modal-open', () => { if (this.searchQuery) this.openModal(); });
            window.addEventListener('ahy-modal-close', () => this.closeModal());
            window.addEventListener('resize', () => { this.isMobile = window.innerWidth < 768; });
            window.addEventListener('mousemove', (e) => this._popDragMove(e));
            window.addEventListener('mouseup', () => this._popDragEnd());
        },

        openModal() {
            this.$el.classList.add('is-open');
            document.body.classList.add('ahy-search-open');
            window.scrollTo(0, 0);
        },

        closeModal() {
            this.$el.classList.remove('is-open');
            document.body.classList.remove('ahy-search-open');
            if (window._ahySearchPushCount > 0) {
                const n = window._ahySearchPushCount;
                window._ahySearchPushCount = 0;
                window._ahySuppressPopstateReload = true;
                history.go(-n);
            } else {
                history.replaceState(history.state, '', window._ahyOriginalUrl);
            }
        },

        async fetch() {
            this.loading = true;
            try {
                const url = new URL(this.productsApiUrl);
                url.searchParams.set('search_token', this.searchToken);
                url.searchParams.set('q', this.wasCorrection && this.correctedQuery ? this.correctedQuery : this.searchQuery);
                url.searchParams.set('page', this.page);
                url.searchParams.set('per_page', this.pageSize);
                url.searchParams.set('include_variants', '1');
                /*
                 * Grouped by key, joined with the unit separator — identical to
                 * web/js/plp/search-results.js.
                 *
                 * This used to be `forEach(f => set(f.key, f.value))`, which calls
                 * set() once per filter. set() REPLACES any existing value for that
                 * key, so selecting two values of the same facet (Black and Camo)
                 * sent only the last one — the overlay silently dropped every
                 * multi-select filter. The results page never had this bug, which is
                 * why the two surfaces disagreed on counts.
                 */
                const filterMap = {};
                this.activeFilters.forEach(f => {
                    if (!filterMap[f.key]) filterMap[f.key] = [];
                    filterMap[f.key].push(f.value);
                });
                Object.entries(filterMap).forEach(([key, vals]) => url.searchParams.set(key, vals.join('\x1F')));
                if (this.activePriceMin !== '') url.searchParams.set('price_min', this.activePriceMin);
                if (this.activePriceMax !== '') url.searchParams.set('price_max', this.activePriceMax);
                if (this.sort !== 'relevance') url.searchParams.set('sort', this.sort);

                let resp = await window.fetch(url.toString(), { credentials: 'include', cache: 'no-store' });
                if (resp.status === 401) {
                    await window.ahyTokenRefresh.refresh();
                    url.searchParams.set('search_token', this.searchToken);
                    resp = await window.fetch(url.toString(), { credentials: 'include', cache: 'no-store' });
                }
                const data = await resp.json();

                if (data.success) {
                    this.error         = false;
                    this.results       = data.data || [];
                    this.total         = (data.pagination && data.pagination.total) || data.total || 0;
                    this.pageSize      = (data.pagination && data.pagination.per_page) || this.pageSize;
                    this.totalPages    = Math.max(1, Math.ceil(this.total / this.pageSize));
                    this.apiFacets     = data.facets || [];
                    this.wasCorrection = !!data.was_corrected;
                    this.correctedQuery = data.corrected_query || this.searchQuery;
                    this.originalQuery  = data.original_query  || this.searchQuery;
                    this.suggestedQuery = data.suggested_query || '';
                    this.buildFilters();
                    if (this.results.length === 0) { this.fetchPopularProducts(); this.fetchPopularSearches(); }

                    /*
                     * Host extension point — same call the results-page and
                     * category components make. Without it the overlay was the
                     * one surface enrichers could not reach, which is why
                     * Everest's star ratings and member prices had to be wired
                     * in by forking this component into the theme.
                     */
                    if (window.FalcoSense) window.FalcoSense._runEnrichers(this.results, this);
                } else {
                    /*
                     * Without this branch a failed API response fell through with
                     * results still empty, so the overlay showed "no matches were
                     * found" for a query that has results. See the equivalent
                     * handling in web/js/plp/search-results.js.
                     */
                    this.error = true;
                    console.error('[AhyModal] Search API error:', data);
                }
            } catch(e) {
                this.error = true;
                console.error('[AhyModal] fetch error', e);
            }
            this.loading = false;
        },

        async fetchPopularProducts() {
            if (this.popularProducts.length) return;
            try {
                const url = new URL(this.productsApiUrl);
                url.searchParams.set('search_token', this.searchToken);
                url.searchParams.set('q', 'outdoor');
                url.searchParams.set('per_page', '8');
                url.searchParams.set('include_variants', '1');
                const resp = await window.fetch(url.toString(), { credentials: 'include', cache: 'no-store' });
                const data = await resp.json();
                if (data.success && data.data) {
                    this.popularProducts = data.data;
                } else if (data.products) {
                    this.popularProducts = data.products;
                }
                console.log('[Popular]', this.popularProducts.length, 'products loaded');
                this.$nextTick(() => {
                    const el = this.$refs.popScroll;
                    if (el) this.popularScrollMax = el.scrollWidth - el.clientWidth;
                });
            } catch(e) { console.error('[Popular] fetch error', e); }
        },

        async fetchPopularSearches() {
            if (this.popularSearches.length) return;
            try {
                const suggestUrl = this.productsApiUrl.replace('/api/v1/products', '/api/v1/suggest');
                const url = new URL(suggestUrl);
                url.searchParams.set('search_token', this.searchToken);
                const resp = await window.fetch(url.toString(), { credentials: 'include', cache: 'no-store' });
                const data = await resp.json();
                this.popularSearches = (data.suggestions || data.terms || data.results || []).slice(0, 8);
            } catch(e) { console.error('[PopularSearches] fetch error', e); }
        },

        buildFilters() {
            if (!this.apiFacets.length) return;
            const priceFacet = this.apiFacets.find(f => f.key === 'price');
            if (priceFacet) this.priceRange = { min: priceFacet.min ?? null, max: priceFacet.max ?? null };
            this.filters = this.apiFacets
                .filter(f => f.key !== 'price' && f.key !== 'category')
                .map(f => ({
                    key:       f.key,
                    label:     f.key === 'brand' ? 'Shop By Brand' : f.label,
                    collapsed: false,
                    showAll:   false,
                    options:   (f.options || []).map(o => ({ value: o.value, count: o.count })),
                }));
        },

        sortedResults() { return this.results; },

        searchExact(q) {
            window.location.href = '/catalogsearch/result/?q=' + encodeURIComponent(q);
        },
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
                if (progress < 1) {
                    this._scrollAnimFrame = requestAnimationFrame(step);
                } else {
                    this._scrollAnimFrame = null;
                }
            };
            this._scrollAnimFrame = requestAnimationFrame(step);
        },
        _findVisibleById(id) {
            const matches = document.querySelectorAll('#' + id);
            for (const el of matches) { if (el.offsetParent !== null) return el; }
            return this.$el.querySelector('#' + id);
        },

        _scrollToResults() {
            const el = this._findVisibleById('ahy-pagination-anchor');
            if (!el) return;
            const y = Math.max(0, el.getBoundingClientRect().top + window.pageYOffset);
            this._animateScrollTo(y);
        },

        // Used only for pagination clicks. Same anchor as _scrollToResults(),
        // kept as its own method for clarity at the call site.
        _scrollModalToTop() {
            const el = this._findVisibleById('ahy-pagination-anchor');
            const y = el ? Math.max(0, el.getBoundingClientRect().top + window.pageYOffset) : 0;
            this._animateScrollTo(y);
        },

        toggleFilter(key, label, value) {
            const idx = this.activeFilters.findIndex(f => f.key === key && f.value === value);
            if (idx >= 0) this.activeFilters.splice(idx, 1);
            else this.activeFilters.push({ key, label, value });
            this.page = 1;
            this._scrollToResults();
            this.fetch();
        },

        removeFilter(key, value) {
            this.activeFilters = this.activeFilters.filter(f => !(f.key === key && f.value === value));
            this.page = 1; this._scrollToResults(); this.fetch();
        },

        applyPriceFilter() { this.page = 1; this._scrollToResults(); this.fetch(); },
        clearPriceFilter() { this.activePriceMin = ''; this.activePriceMax = ''; this.activePriceRange = null; this.page = 1; this._scrollToResults(); this.fetch(); },
        clearAllFilters() { this.activeFilters = []; this.activePriceMin = ''; this.activePriceMax = ''; this.activePriceRange = null; this.page = 1; this._scrollToResults(); this.fetch(); },

        priceRangeBuckets() {
            if (this.priceRange.min === null) return [];
            return [
                { label: 'Under $50',     min: '',   max: 50   },
                { label: '$50 – $100',    min: 50,   max: 100  },
                { label: '$100 – $250',   min: 100,  max: 250  },
                { label: '$250 – $500',   min: 250,  max: 500  },
                { label: '$500 – $1,000', min: 500,  max: 1000 },
                { label: 'Over $1,000',   min: 1000, max: ''   },
            ].filter(b => {
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

       goToPage(p) { this.page = p; this.paginationLoading = true; this.fetch().then(() => { this.paginationLoading = false; this.$nextTick(() => this._scrollModalToTop()); }); },
        setSort(val) { this.sort = val; this.page = 1; this.fetch(); },
        sortLabel() {
            return { relevance: 'Relevance', price_asc: 'Price: Low to high', price_desc: 'Price: High to low' }[this.sort] || 'Relevance';
        },

        FALLBACK_IMG: ((window.FalcoSense && window.FalcoSense.config && window.FalcoSense.config.fallbackImage) || ''),
        isFallbackImg(image) {
            return this.imgUrl(image) === this.FALLBACK_IMG;
        },
        imgUrl(image) {
            if (!image || image.includes('no_selection')) return this.FALLBACK_IMG;
            if (image.includes('falcosense/800x800')) return image;
            const isHttp = image.startsWith('http');
            const path = isHttp ? image.replace(/^https?:\/\/[^/]+/, '') : image;
            const m = path.match(/\/catalog\/product\/(.+)$/);
            if (!m && isHttp) return this.FALLBACK_IMG;
            const rel = m ? m[1] : path.replace(/^\/+/, '');
            if (!rel) return this.FALLBACK_IMG;
            const filename = rel.split('/').pop();
            if (!filename) return this.FALLBACK_IMG;
            const c1 = filename[0], c2 = filename[1] || c1;
            return '/media/falcosense/800x800/' + c1 + '/' + c2 + '/' + filename;
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
            console.log('[AhyModal] addSimpleToCart pid:', pid, 'url:', '<?= $escaper->escapeJs($addToCartUrl) ?>');
            this.cartLoading = { ...this.cartLoading, [pid]: true };
            try {
                const params = new URLSearchParams({ product: pid, qty: 1, form_key: hyva.getFormKey() });
                const resp = await fetch('<?= $escaper->escapeJs($addToCartUrl) ?>', {
                    method: 'POST',
                    body: params,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
                });
                console.log('[AhyModal] addToCart resp:', resp.status, 'ok:', resp.ok, 'redirected:', resp.redirected, 'url:', resp.url);
                if (resp.ok || resp.redirected) {
                    window.dispatchEvent(new CustomEvent('reload-customer-section-data'));
                    window.dispatchEvent(new CustomEvent('toggle-cart'));
                    typeof window.dispatchMessages !== 'undefined' && window.dispatchMessages([{ type: 'success', text: 'Product added to cart.' }], 3000);
                }
            } catch(e) { console.error('[AhyModal] addToCart error', e); }
            finally { this.cartLoading = { ...this.cartLoading, [pid]: false }; }
        },

        async addToWishlistModal(productId) {
            const pid = productId;
            this.wishLoading = { ...this.wishLoading, [pid]: true };
            try {
                const resp = await window.fetch(BASE_URL + 'wishlist/index/add/', {
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
            } catch(e) { console.error('[AhyModal] wishlist error', e); }
            this.wishLoading = { ...this.wishLoading, [pid]: false };
        },

        _popScrollUpdate(el) {
            this.popularScrollPos = el.scrollLeft;
            this.popularScrollMax = el.scrollWidth - el.clientWidth;
        },
        _popDragStart(e, el) {
            this._popDragging = true;
            this._popDragStartX = e.pageX - el.getBoundingClientRect().left;
            this._popDragScrollLeft = el.scrollLeft;
            this._popDragMoved = false;
            el.classList.add('is-dragging');
        },
        _popDragMove(e) {
            if (!this._popDragging) return;
            const el = this.$refs.popScroll;
            if (!el) return;
            const x = e.pageX - el.getBoundingClientRect().left;
            const walk = x - this._popDragStartX;
            el.scrollLeft = this._popDragScrollLeft - walk;
            if (Math.abs(walk) > 5) this._popDragMoved = true;
        },
        _popDragEnd() {
            if (!this._popDragging) return;
            this._popDragging = false;
            const el = this.$refs.popScroll;
            if (el) el.classList.remove('is-dragging');
        },
        _popCardWidth() {
            const el = this.$refs.popScroll;
            if (!el) return 0;
            const card = el.querySelector('.fs-pop-card');
            return card ? card.offsetWidth + 12 : el.clientWidth;
        },
        _popScrollPrev() {
            const el = this.$refs.popScroll;
            if (el) el.scrollBy({ left: -this._popCardWidth(), behavior: 'smooth' });
        },
        _popScrollNext() {
            const el = this.$refs.popScroll;
            if (el) el.scrollBy({ left: this._popCardWidth(), behavior: 'smooth' });
        },
    };
}
})(window);
