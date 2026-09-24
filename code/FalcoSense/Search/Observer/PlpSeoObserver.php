<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Model\Plp\PageContext;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Page\Config as PageConfig;

/**
 * Faceted-navigation hygiene for FalcoSense listings.
 *
 * A filtered, sorted or paginated listing is the same products in a different
 * arrangement. Left indexable, every filter combination becomes its own URL in
 * the index, competing with the clean category page and spreading ranking
 * signals across near-duplicates. NOINDEX,FOLLOW is the standard remedy: keep
 * the page out of the index, but let crawlers follow through to the products.
 *
 * WHY ROBOTS AND NOT CANONICAL
 * ----------------------------
 * Only robots is set here, deliberately. Magento's own
 * catalog/seo/category_canonical_tag already points paged and filtered CATEGORY
 * URLs at the clean category URL; emitting a second <link rel="canonical"> would
 * conflict with it. Enable that native setting for the canonical half. This
 * covers the noindex half, and also the search results page, which has no native
 * canonical at all.
 *
 * WHY layout_generate_blocks_after
 * --------------------------------
 * Late enough that the layout (and therefore the page type) is known, early
 * enough that PageConfig changes still reach <head>. Setting robots after the
 * head block has rendered would silently do nothing.
 *
 * INDEPENDENT OF WHO RENDERS THE GRID
 * -----------------------------------
 * This reads the REQUEST, not the block tree, so it behaves the same whether
 * FalcoSense renders the listing or a host module has replaced the grid with its
 * own (as Ahy_PlpRevamp does on Everest). The URL is what search engines index.
 *
 * Ported from Ahy_SmartSearchLuma, rewritten against FalcoSense's own
 * PageContext/PlpQuery instead of that module's PlpContextProvider.
 */
class PlpSeoObserver implements ObserverInterface
{
    /**
     * Parameters that do not change which products are shown, so their presence
     * alone must not trigger a noindex. `q` defines the search itself, `id`/`cat`
     * identify the category, and the rest are Magento's own store-switching and
     * session plumbing.
     */
    private const NEUTRAL_PARAMS = [
        'id', 'q', 'cat', '___store', '___from_store', 'SID', 'sid',

        /*
         * `p` and `sort` are listed here NOT because they are harmless, but
         * because isCanonicalView() already judges them — and judges them more
         * precisely than this check can.
         *
         * isCanonicalView() returns false for page 2+, and for any sort other
         * than the default. So a genuinely non-canonical ?p=2 or ?sort=price_asc
         * is already caught before this method runs.
         *
         * Without them here, ?p=1 — page ONE, identical content to the clean
         * URL — was treated as an extra parameter and noindexed. On a storefront
         * whose category links carry ?p=1 (Everest's do), that silently
         * noindexed every category page on the site. Exactly the damage this
         * observer exists to prevent, caused by the observer.
         *
         * Price bounds are deliberately NOT listed: buildCategoryQuery() does
         * not read them from the request, so isCanonicalView() cannot see them
         * and this check is the only thing catching ?price_min=50.
         */
        'p', 'sort',
    ];

    public function __construct(
        private readonly PageContext $pageContext,
        private readonly PageConfig $pageConfig,
        private readonly HttpRequest $request,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $query = null;

        if ($this->pageContext->isSearchPage()) {
            $query = $this->pageContext->buildSearchQuery();
        } elseif ($this->pageContext->isCategoryPage()) {
            $query = $this->pageContext->buildCategoryQuery();
        }

        if ($query === null) {
            return; // not a FalcoSense listing — leave the page's meta alone
        }

        if ($query->isCanonicalView() && !$this->hasExtraQueryParams()) {
            return; // clean, canonical listing: this is the one we WANT indexed
        }

        $this->pageConfig->setRobots('NOINDEX,FOLLOW');
    }

    /**
     * Catches refinements PlpQuery does not model — tracking parameters, layered
     * navigation attributes it does not parse, anything a host module adds. If an
     * unrecognised parameter is present, treat the URL as non-canonical rather
     * than risk indexing a duplicate.
     */
    private function hasExtraQueryParams(): bool
    {
        foreach ($this->request->getParams() as $key => $value) {
            if (in_array($key, self::NEUTRAL_PARAMS, true)) {
                continue;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }
}
