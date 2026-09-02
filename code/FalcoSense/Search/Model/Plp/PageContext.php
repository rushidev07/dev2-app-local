<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Plp;

use FalcoSense\Search\Helper\Data;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Detects whether the current request is a search or category results page
 * and, if so, builds the PlpQuery for it.
 *
 * Search stays scoped to its canonical (page 1, no filters, default sort)
 * view only — anything else (a filter, a page beyond 1, a bookmarked/shared
 * filtered URL) falls straight through to Alpine's client-side fetch(), same
 * as before.
 *
 * Category renders server-side for *every* page/sort combination, not just
 * the canonical one — category/results.phtml's own Alpine `init()` only ever
 * reads `p`/`sort` from the URL to begin with (filters there are pure
 * post-load client state, never URL-encoded), so there's no "filtered view"
 * for SSR to skip the way there is for search.
 *
 * Both of this module's search routes resolve to the full action names
 * checked below without the dispatch-name pitfall Ahy_SmartSearchLuma hit
 * earlier (there, a route id that didn't match its frontName produced the
 * wrong full action name and silently broke this exact kind of check) —
 * confirmed here because both `catalogsearch` and `fs` route ids already
 * equal their frontNames in etc/frontend/routes.xml.
 */
class PageContext
{
    private const ACTIONS_SEARCH = ['catalogsearch_result_index', 'fs_search_index'];
    private const ACTIONS_CATEGORY = ['catalog_category_view'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly Data $helper,
        private readonly LayerResolver $layerResolver,
    ) {
    }

    public function isSearchPage(): bool
    {
        return in_array((string) $this->request->getFullActionName(), self::ACTIONS_SEARCH, true);
    }

    /**
     * Null when this isn't a search page, or the query is blank/whitespace,
     * or the request carries any param that would make this a non-canonical
     * view (a filter, a page beyond 1, a bookmarked/shared filtered URL) —
     * SSR only ever renders the plain, unfiltered first page.
     */
    public function buildSearchQuery(): ?PlpQuery
    {
        if (!$this->isSearchPage()) {
            return null;
        }

        $q = trim((string) $this->request->getParam('q', ''));
        if ($q === '') {
            return null;
        }

        if (!$this->isCanonicalRequest()) {
            return null;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = 0;
        }

        return new PlpQuery(
            contextType: PlpQuery::CONTEXT_SEARCH,
            storeId: $storeId,
            platformStoreId: $this->helper->getPlatformStoreId($storeId),
            page: 1,
            perPage: $this->helper->getProductsPerPage($storeId),
            sort: 'relevance',
            searchQuery: $q,
        );
    }

    /**
     * True only when none of the params that would make results.phtml's
     * Alpine component render a non-default view are present — matches
     * PlpQuery::isCanonicalView() in spirit, checked against the raw request
     * before a PlpQuery even exists, since page/filters/sort here come from
     * query params, not from a query object yet.
     */
    private function isCanonicalRequest(): bool
    {
        $page = (string) $this->request->getParam('p', '');
        if ($page !== '' && $page !== '1') {
            return false;
        }

        foreach (['brand', 'price_min', 'price_max', 'sort', 'bypass_spell'] as $param) {
            if ((string) $this->request->getParam($param, '') !== '') {
                return false;
            }
        }

        return true;
    }

    public function isCategoryPage(): bool
    {
        return in_array((string) $this->request->getFullActionName(), self::ACTIONS_CATEGORY, true);
    }

    /**
     * Unlike buildSearchQuery(), this renders server-side for every request
     * on the category page — any page number, any sort — not just the
     * canonical view. Null only when this isn't a category page, or there's
     * no resolvable current category (layer navigation failed, or this is
     * some edge-case category with no id).
     */
    public function buildCategoryQuery(): ?PlpQuery
    {
        if (!$this->isCategoryPage()) {
            return null;
        }

        try {
            $category = $this->layerResolver->get()->getCurrentCategory();
        } catch (\Throwable) {
            $category = null;
        }
        if (!$category || !$category->getId()) {
            return null;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = 0;
        }

        $page = (int) $this->request->getParam('p', 1);
        if ($page < 1) {
            $page = 1;
        }
        $sort = trim((string) $this->request->getParam('sort', ''));

        return new PlpQuery(
            contextType: PlpQuery::CONTEXT_CATEGORY,
            storeId: $storeId,
            platformStoreId: $this->helper->getPlatformStoreId($storeId),
            page: $page,
            perPage: $this->helper->getProductsPerPage($storeId),
            sort: $sort !== '' ? $sort : 'relevance',
            categoryId: (int) $category->getId(),
            categoryName: (string) $category->getName(),
        );
    }
}
