<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block\Plp;

use FalcoSense\Search\Api\PlpDataProviderInterface;
use FalcoSense\Search\Model\Plp\JsonLdBuilder;
use FalcoSense\Search\Model\Plp\PageContext;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Emits ItemList structured data for a FalcoSense listing.
 *
 * MOUNTED SEPARATELY FROM THE GRID, ON PURPOSE
 * --------------------------------------------
 * This is its own block rather than markup inside the grid template, because a
 * host storefront may replace our grid entirely — Everest's Ahy_PlpRevamp does
 * exactly that, with `<referenceBlock name="ahy_smartsearch_category"
 * remove="true"/>`. Structured data attached to that block would disappear with
 * it, silently taking the page's SEO markup along with someone else's design
 * decision. Mounted independently, it survives.
 *
 * NO EXTRA API CALL
 * -----------------
 * It asks the provider for the same PlpQuery the grid used, and the provider
 * memoises per request (see FalcoSensePlpProvider::$memo), so this reuses the
 * response the grid already fetched.
 *
 * CANONICAL VIEWS ONLY
 * --------------------
 * Filtered, sorted and paged URLs are marked NOINDEX,FOLLOW by PlpSeoObserver.
 * Emitting structured data for a page we have asked search engines to skip is at
 * best noise and at worst a mixed signal, so those views render nothing.
 */
class StructuredData extends Template
{
    public function __construct(
        Context $context,
        private readonly PageContext $pageContext,
        private readonly PlpDataProviderInterface $plpProvider,
        private readonly JsonLdBuilder $jsonLdBuilder,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return string a ready-to-print <script type="application/ld+json"> block,
     *                or '' when this page should not carry one
     */
    public function getJsonLd(): string
    {
        $query = null;

        if ($this->pageContext->isSearchPage()) {
            $query = $this->pageContext->buildSearchQuery();
        } elseif ($this->pageContext->isCategoryPage()) {
            $query = $this->pageContext->buildCategoryQuery();
        }

        if ($query === null || !$query->isCanonicalView()) {
            return '';
        }

        try {
            $result = $this->plpProvider->fetch($query);
        } catch (\Throwable $e) {
            /*
             * Structured data is an enhancement. A platform outage must degrade
             * to "no JSON-LD", never to a broken listing page.
             */
            $this->_logger->warning('[FalcoSense][PLP] structured data skipped: ' . $e->getMessage());
            return '';
        }

        return $this->jsonLdBuilder->build($result, $query);
    }
}
