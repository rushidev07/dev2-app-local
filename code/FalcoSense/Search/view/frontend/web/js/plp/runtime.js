/**
 * FalcoSense PLP runtime — configuration and extension points.
 * ---------------------------------------------------------------------------
 * Loaded before the PLP components. Provides the two seams that let a host
 * storefront change behaviour without forking the search logic:
 *
 *   1. CONFIG    — values that differ per store (seller names, fallback image,
 *                  CDN base). Previously hardcoded to Everest inside the
 *                  component, which is why a non-Everest store would have shown
 *                  the Everest logo for every missing product image.
 *
 *   2. ENRICHERS — callbacks run after each result set loads. This is how a
 *                  store layers its own data (review ratings, member pricing,
 *                  seller names) onto FalcoSense results without editing the
 *                  component. Everest's PlpRevamp module currently does this by
 *                  maintaining a full copy of the component; with this hook it
 *                  registers three functions instead.
 *
 * Both are optional. A store that sets nothing gets sane, brand-neutral
 * defaults and no extra network calls.
 */
(function (w) {
    'use strict';

    var FS = w.FalcoSense = w.FalcoSense || {};

    /**
     * Defaults are deliberately empty/neutral, NOT Everest's values — a store
     * that forgets to configure gets no free-shipping badges and no fallback
     * image, which is visibly "unconfigured" rather than silently wrong.
     */
    var defaults = {
        /** Seller/brand names that qualify for the free shipping badge. */
        freeShippingSellers: [],
        /** Shown when a product has no image. Empty = render nothing. */
        fallbackImage: '',
        /** Origin for resized product images. Empty = same-origin /media paths. */
        cdnBase: '',
        /** Default seller label when a product carries no brand. */
        defaultSeller: '',
        /** Pixels of breathing room above the grid when scrolling to results. */
        scrollOffset: 0
    };

    FS.config = Object.assign({}, defaults, FS.config || {});

    /**
     * Merge in store-specific settings. Called from a template with values the
     * block read out of admin config, so nothing store-specific is baked into
     * this file.
     */
    FS.configure = function (overrides) {
        Object.assign(FS.config, overrides || {});
        return FS.config;
    };

    /**
     * The "Sold By <seller>" line on a product card.
     *
     * Every card template used to write `product.brand || 'The Everest
     * Marketplace'` inline, which put one merchant's name on every other
     * merchant's storefront. The default now comes from admin config, and a
     * store that sets none gets an empty string — the templates hide the line
     * rather than print a dangling "Sold By ".
     *
     * @param {string=} brand   Seller on the product, if the API returned one.
     * @param {string=} prefix  Defaults to "Sold By"; cards that say "Sold And
     *                          Shipped By" pass their own.
     * @returns {string} The full label, or '' when there is no seller to name.
     */
    FS.soldBy = function (brand, prefix) {
        var seller = brand || FS.config.defaultSeller || '';
        if (!seller) return '';
        return (prefix || 'Sold By') + ' ' + seller;
    };

    /** Convenience for inline onerror handlers, which cannot see FS.config. */
    FS.fallbackImage = function () {
        return FS.config.fallbackImage || '';
    };

    FS._enrichers = FS._enrichers || [];

    /**
     * Register a callback invoked with the product array each time results load
     * (initial render, pagination, filtering, sorting).
     *
     * The callback receives (products, component) and may mutate the component's
     * own state — that is how Everest's Yotpo ratings and CaliberNation prices
     * attach themselves. Errors are contained: a failing enricher logs and is
     * skipped, it never takes the product grid down with it.
     *
     *   FalcoSense.enrich(function (products, cmp) {
     *       fetch(MY_ENDPOINT + '?ids=' + products.map(p => p.product_id).join(','))
     *           .then(r => r.json())
     *           .then(d => { cmp.myPrices = d.prices; });
     *   });
     */
    FS.enrich = function (fn) {
        if (typeof fn === 'function') FS._enrichers.push(fn);
        return FS;
    };

    /** Invoked by the components after every successful load. */
    FS._runEnrichers = function (products, cmp) {
        if (!Array.isArray(products) || !products.length) return;
        FS._enrichers.forEach(function (fn) {
            try {
                fn(products, cmp);
            } catch (e) {
                console.error('[FalcoSense] enricher failed (skipped):', e);
            }
        });
    };
})(window);
