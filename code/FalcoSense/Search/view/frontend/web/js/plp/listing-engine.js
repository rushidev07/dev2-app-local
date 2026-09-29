/**
 * FalcoSense listing engine — the search/filter/paginate behaviour shared by
 * every listing surface.
 * ---------------------------------------------------------------------------
 * There were three near-copies of this logic: the search results page, the
 * category grid and the header overlay. They drifted, as copies do. Sorting was
 * sent to the platform by two of them and done in the browser by the third, so
 * "Price: Low to High" on the search page reordered only the page in hand.
 * Filter panels sprang back open on the overlay but not elsewhere. The overlay
 * re-sent the platform's own spelling correction back to it. Each of those was
 * one surface disagreeing with the other two, and none of them was a decision
 * anybody made.
 *
 * listingCore() is the single implementation. A surface calls it with the few
 * things that genuinely differ, then adds its own behaviour on top:
 *
 *     Object.assign(FalcoSense.listingCore({ pageSize: 12 }), {
 *         openModal() { ... },      // overlay-only
 *         fetch() { ... },          // override entirely if you must
 *     })
 *
 * Object.assign puts the surface's own properties last, so anything it defines
 * wins over the core. Overriding is a supported escape hatch, not a mistake.
 *
 * WHAT IS NOT IN HERE
 * -------------------
 * init(), scrolling, and anything about how a surface is opened, closed or
 * positioned. Those are genuinely per-surface: the overlay scrolls its own
 * panel, the category grid scrolls the page, and the search page seeds itself
 * from an SSR payload the other two do not have. Forcing those together would
 * be the same mistake in the other direction.
 */
(function (w) {
    'use strict';

    var FS = w.FalcoSense = w.FalcoSense || {};

    /* A host that has already supplied its own engine keeps it — same rule the
       three components use for their own names. */
    if (FS.listingCore) return;

    var DEFAULT_SORT_LABELS = {
        relevance:  'Relevance',
        price_asc:  'Price: Low to high',
        price_desc: 'Price: High to low'
    };

    /**
     * @param {Object}  [o]                    Per-surface options.
     * @param {number}  [o.pageSize=50]        Results requested per page.
     * @param {Object}  [o.sortLabels]         Override the sort dropdown copy.
     * @param {boolean} [o.clientPriceFilter]  Also filter by price in the browser
     *        after the platform has filtered. The search and category grids do
     *        this today and the overlay does not; it is an option rather than a
     *        decision because nobody has yet confirmed whether the platform and
     *        this file agree on which price to compare (list, or special when
     *        lower). While they might disagree, removing it could show products
     *        outside the band the shopper chose. Once that is confirmed, this
     *        goes away and the platform is simply trusted.
     */
    FS.listingCore = function (o) {
        o = o || {};
        var sortLabels = Object.assign({}, DEFAULT_SORT_LABELS, o.sortLabels || {});

        return {
            /* ---- state shared by every surface ---------------------------- */
            results: [],
            filters: [],
            apiFacets: [],
            activeFilters: [],
            priceRange: { min: null, max: null },
            /* Recomputed by buildFilters(); templates render it directly rather
               than calling priceRangeBuckets() inside an x-for, which would
               rebuild the array on every Alpine re-evaluation. */
            priceBuckets: [],
            activePriceMin: '',
            activePriceMax: '',
            activePriceRange: null,
            priceCollapsed: false,
            total: 0,
            page: 1,
            pageSize: o.pageSize || 50,
            totalPages: 1,
            sort: 'relevance',
            loading: false,
            paginationLoading: false,
            error: false,
            cartLoading: {},
            wishLoading: {},

            /* ---- images ---------------------------------------------------- */

            FALLBACK_IMG: ((w.FalcoSense && w.FalcoSense.config && w.FalcoSense.config.fallbackImage) || ''),

            imgUrl: function (image) {
                if (!image || image.indexOf('no_selection') !== -1) return this.FALLBACK_IMG;
                if (image.indexOf('falcosense/800x800') !== -1) return image;
                var path = image;
                if (image.indexOf('http') === 0) {
                    var m = image.match(/\/catalog\/product\/(.+)$/);
                    if (!m) return image;
                    path = m[1];
                } else {
                    path = path.replace(/^\/+/, '');
                }
                if (!path) return this.FALLBACK_IMG;
                var filename = path.split('/').pop();
                if (!filename) return this.FALLBACK_IMG;
                var c1 = filename[0], c2 = filename[1] || c1;
                var cdn = (w.FalcoSense && w.FalcoSense.config.cdnBase) || '';
                return cdn + '/media/falcosense/800x800/' + c1 + '/' + c2 + '/' + filename;
            },

            /* Compares the RESOLVED url: taking the raw value meant a product
               whose image resolves to the fallback was not detected as one. */
            isFallbackImg: function (image) {
                return this.imgUrl(image) === this.FALLBACK_IMG;
            },

            handleImgError: function (event) {
                var el = event.target;
                if (el.dataset.imgFailed) {
                    el.src = this.FALLBACK_IMG;
                    el.style.opacity = '0.45';
                    return;
                }
                el.dataset.imgFailed = '1';
                var orig = el.getAttribute('data-original-src');
                if (!orig || orig.indexOf('no_selection') !== -1) {
                    el.src = this.FALLBACK_IMG;
                    el.style.opacity = '0.45';
                    return;
                }
                el.src = orig.indexOf('http') === 0 ? orig : '/media/catalog/product' + orig;
            },

            /* ---- price buckets --------------------------------------------- */

            priceRangeBuckets: function () {
                if (this.priceRange.min === null) return [];
                var rangeMax = this.priceRange.max !== null ? this.priceRange.max : Infinity;
                var self = this;
                return [
                    { label: 'Under $50',      min: '',   max: 50 },
                    { label: '$50 – $100',     min: 50,   max: 100 },
                    { label: '$100 – $250',    min: 100,  max: 250 },
                    { label: '$250 – $500',    min: 250,  max: 500 },
                    { label: '$500 – $1,000',  min: 500,  max: 1000 },
                    { label: 'Over $1,000',    min: 1000, max: '' }
                ].filter(function (b) {
                    var lo = b.min === '' ? 0 : b.min;
                    var hi = b.max === '' ? Infinity : b.max;
                    return lo < rangeMax && hi > self.priceRange.min;
                });
            },

            togglePriceRange: function (bucket) {
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

            /* ---- filters ---------------------------------------------------- */

            isFilterActive: function (key, value) {
                return this.activeFilters.some(function (f) { return f.key === key && f.value === value; });
            },

            toggleFilter: function (key, label, value) {
                var idx = this.activeFilters.findIndex(function (f) { return f.key === key && f.value === value; });
                if (idx >= 0) this.activeFilters.splice(idx, 1);
                else this.activeFilters.push({ key: key, label: label, value: value });
                this._afterFilterChange();
            },

            removeFilter: function (key, value) {
                this.activeFilters = this.activeFilters.filter(function (f) {
                    return !(f.key === key && f.value === value);
                });
                this._afterFilterChange();
            },

            clearAllFilters: function () {
                this.activeFilters = [];
                this.activePriceMin = '';
                this.activePriceMax = '';
                this.activePriceRange = null;
                this._afterFilterChange();
            },

            clearPriceFilter: function () {
                this.activePriceMin = '';
                this.activePriceMax = '';
                this.activePriceRange = null;
                this._afterFilterChange();
            },

            applyPriceFilter: function () {
                this._afterFilterChange();
            },

            /* Every filter change does the same four things. They used to be
               written out at each call site, which is how the search page ended
               up writing the URL on some of them and not others. */
            _afterFilterChange: function () {
                this.page = 1;
                this._writeUrl();
                this._scrollToResults();
                this.fetch();
            },

            /**
             * Rebuild the facet list from the platform's response.
             *
             * Carries each panel's open/closed and "show all" state across the
             * refetch, and floats selected values to the top so a ticked filter
             * does not disappear behind a "show more" when counts change.
             */
            buildFilters: function () {
                if (this.apiFacets.length === 0) return;

                var priceFacet = this.apiFacets.find(function (f) { return f.key === 'price'; });
                if (priceFacet) {
                    this.priceRange = {
                        min: priceFacet.min !== undefined && priceFacet.min !== null ? priceFacet.min : null,
                        max: priceFacet.max !== undefined && priceFacet.max !== null ? priceFacet.max : null
                    };
                    this.priceBuckets = this.priceRangeBuckets();
                }

                var prevState = {};
                this.filters.forEach(function (f) {
                    prevState[f.key] = { collapsed: f.collapsed, showAll: f.showAll };
                });

                var self = this;
                this.filters = this.apiFacets
                    .filter(function (f) { return f.key !== 'price' && f.key !== 'category'; })
                    .map(function (f) {
                        var prev = prevState[f.key] || {};
                        return {
                            key: f.key,
                            label: f.key === 'brand' ? 'Shop By Brand' : f.label,
                            collapsed: prev.collapsed !== undefined ? prev.collapsed : false,
                            showAll: prev.showAll !== undefined ? prev.showAll : false,
                            options: (f.options || [])
                                .map(function (opt) { return { value: opt.value, count: opt.count }; })
                                .sort(function (a, b) {
                                    var aA = self.isFilterActive(f.key, a.value) ? -1 : 1;
                                    var bA = self.isFilterActive(f.key, b.value) ? -1 : 1;
                                    return aA - bA;
                                })
                        };
                    });

                this._afterBuildFilters();
            },

            /**
             * Hook: run once the facet list has been rebuilt.
             *
             * The search page uses it to apply a brand from the URL the first
             * time that brand appears as a facet — it cannot be applied earlier
             * because until the platform answers, there is no facet list to
             * match it against.
             */
            _afterBuildFilters: function () {},

            /* ---- sorting ----------------------------------------------------- */

            sortLabel: function () {
                return sortLabels[this.sort] || sortLabels.relevance;
            },

            setSort: function (val) {
                /* Back to page 1: page 4 of a relevance sort is not page 4 of a
                   price sort. */
                this.sort = val;
                this.page = 1;
                this._writeUrl();
                this._scrollToResults();
                this.fetch();
            },

            /**
             * What the template renders.
             *
             * Deliberately does NOT sort. The platform returns the page already
             * ordered (fetch() sends `sort`), and re-sorting here would layer
             * this file's idea of "price" over the platform's — two sources of
             * truth that disagree exactly at the page boundaries.
             */
            sortedResults: function () {
                if (!Array.isArray(this.results)) return [];
                if (!o.clientPriceFilter) return this.results;
                if (this.activePriceMin === '' && this.activePriceMax === '') return this.results;

                var lo = this.activePriceMin !== '' ? parseFloat(this.activePriceMin) : 0;
                var hi = this.activePriceMax !== '' ? parseFloat(this.activePriceMax) : Infinity;
                return this.results.filter(function (p) {
                    var special = parseFloat(p.special_price);
                    var base = parseFloat(p.price || 0);
                    var price = (special && special < base) ? special : base;
                    return price >= lo && (hi === Infinity || price <= hi);
                });
            },

            /* ---- paging -------------------------------------------------------- */

            goToPage: function (p) {
                this.page = p;
                this._writeUrl();
                /*
                 * Scroll AFTER the fetch resolves, never before. fetch() flips
                 * `loading`, which hides the results and collapses the page
                 * height out from under a deep scroll position — scrolling into
                 * that collapse just gets clamped back to the top. The
                 * paginationLoading overlay covers the re-render visually.
                 */
                this.paginationLoading = true;
                var self = this;
                return this.fetch().then(function () {
                    self.paginationLoading = false;
                    self.$nextTick(function () { self._scrollToResults(); });
                });
            },

            /* ---- the request ---------------------------------------------------- */

            /**
             * Hook: add surface-specific query parameters.
             * The search page adds geo_state and bypass_spell; nothing else does.
             */
            _applyQueryParams: function () {},

            /** Hook: called after a successful response has been applied. */
            _afterFetch: function () {},

            /** Overridden by the search page, which has its own refresh path. */
            _refreshToken: function () {
                return w.ahyTokenRefresh ? w.ahyTokenRefresh.refresh() : Promise.resolve();
            },

            /** No-op unless the surface syncs its state to the address bar. */
            _writeUrl: function () {},

            /** No-op unless the surface has somewhere to scroll. */
            _scrollToResults: function () {},

            /* Milliseconds the last request took, for surfaces that report search
               latency back to the platform. */
            lastFetchMs: 0,

            fetch: function () {
                var self = this;
                var t0 = (w.performance && w.performance.now) ? w.performance.now() : 0;
                this.loading = true;

                var url = new URL(this.productsApiUrl);
                url.searchParams.set('search_token', this.searchToken);
                url.searchParams.set('page', this.page);
                url.searchParams.set('per_page', this.pageSize);
                url.searchParams.set('include_variants', '1');

                /*
                 * Grouped by key, joined with the unit separator. Writing
                 * set() once per filter instead silently dropped every
                 * multi-select: set() REPLACES, so picking Black and Camo sent
                 * only Camo. The overlay had that bug and the results page did
                 * not, which is why the two disagreed on counts.
                 */
                var filterMap = {};
                this.activeFilters.forEach(function (f) {
                    if (!filterMap[f.key]) filterMap[f.key] = [];
                    filterMap[f.key].push(f.value);
                });
                Object.keys(filterMap).forEach(function (key) {
                    url.searchParams.set(key, filterMap[key].join('\x1F'));
                });

                if (this.activePriceMin !== '') url.searchParams.set('price_min', this.activePriceMin);
                if (this.activePriceMax !== '') url.searchParams.set('price_max', this.activePriceMax);

                /* Sorting is the platform's job. Doing it in the browser only
                   ever sorted the page in hand. */
                if (this.sort !== 'relevance') url.searchParams.set('sort', this.sort);

                this._applyQueryParams(url);

                return w.fetch(url.toString(), { credentials: 'include', cache: 'no-store' })
                    .then(function (resp) {
                        if (resp.status !== 401) return resp;
                        /* Token expired behind full-page cache. Refresh once and retry. */
                        return Promise.resolve(self._refreshToken()).then(function () {
                            url.searchParams.set('search_token', self.searchToken);
                            return w.fetch(url.toString(), { credentials: 'include', cache: 'no-store' });
                        });
                    })
                    .then(function (resp) { return resp.json(); })
                    .then(function (data) {
                        /* Recorded before _afterFetch, because the search page
                           reports it to the platform from inside that hook. */
                        self.lastFetchMs = t0 ? Math.round(w.performance.now() - t0) : 0;

                        if (!data || !data.success) {
                            /*
                             * Without this branch a failed response left results
                             * empty and the shopper was told "no matches were
                             * found" for a query that has results.
                             */
                            self.error = true;
                            console.error('[FalcoSense] Search API error:', data);
                            return;
                        }
                        self.error = false;
                        self.results = data.data || [];
                        self.total = (data.pagination && data.pagination.total) || data.total || 0;
                        self.pageSize = (data.pagination && data.pagination.per_page) || self.pageSize;
                        self.totalPages = Math.max(1, Math.ceil(self.total / self.pageSize));
                        self.apiFacets = data.facets || [];
                        self.buildFilters();

                        /* Host extension point — review ratings, member pricing,
                           seller names. Contained: a throwing enricher is logged
                           and skipped, never takes the grid down. */
                        if (w.FalcoSense && w.FalcoSense._runEnrichers) {
                            w.FalcoSense._runEnrichers(self.results, self);
                        }

                        self._afterFetch(data);
                    })
                    .catch(function (e) {
                        self.error = true;
                        console.error('[FalcoSense] Search fetch error', e);
                    })
                    .then(function () {
                        self.loading = false;
                    });
            }
        };
    };
})(window);
