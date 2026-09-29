<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\ViewModel;

use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * PDP breadcrumb trail, built server-side.
 *
 * The stock Hyva template derived the category crumbs in JavaScript from
 * document.referrer, so the trail only appeared when the shopper happened to
 * click through from that exact category page - a direct link, refresh, new tab
 * or an arrival from search produced a bare "Home / Product". Building the
 * trail server-side instead means it always renders and is in the initial
 * HTML so crawlers see it too - it prefers the category the shopper actually
 * navigated through (see getCategoryTrail()) and only falls back to the
 * product's own category assignment when there is no such context.
 */
class ProductBreadcrumbs implements ArgumentInterface
{
    /**
     * Categories at level 0 (Root Catalog) and level 1 (the store's own root)
     * are structural containers, never shown to shoppers.
     */
    private const FIRST_VISIBLE_LEVEL = 2;

    private CurrentProduct $currentProduct;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private StoreManagerInterface $storeManager;
    private Registry $registry;

    /** @var array<int, array{label: string, url: ?string}>|null */
    private ?array $crumbs = null;

    public function __construct(
        CurrentProduct $currentProduct,
        CategoryCollectionFactory $categoryCollectionFactory,
        StoreManagerInterface $storeManager,
        Registry $registry
    ) {
        $this->currentProduct = $currentProduct;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->storeManager = $storeManager;
        $this->registry = $registry;
    }

    /**
     * Home, then each ancestor category, then the product. The product's entry
     * has a null url - it is the current page.
     *
     * @return array<int, array{label: string, url: ?string}>
     */
    public function getCrumbs(): array
    {
        if ($this->crumbs !== null) {
            return $this->crumbs;
        }

        $crumbs = [[
            'label' => (string) __('Home'),
            'url' => $this->storeManager->getStore()->getBaseUrl(),
        ]];

        $product = $this->currentProduct->exists() ? $this->currentProduct->get() : null;
        if (!$product) {
            return $this->crumbs = $crumbs;
        }

        foreach ($this->getCategoryTrail($product) as $category) {
            $crumbs[] = [
                'label' => (string) $category->getName(),
                'url' => (string) $category->getUrl(),
            ];
        }

        $crumbs[] = [
            'label' => (string) $product->getName(),
            'url' => null,
        ];

        return $this->crumbs = $crumbs;
    }

    /**
     * Ancestors of the category the shopper actually navigated through,
     * root-first - falling back to the product's most specific category only
     * when there is no such navigation context.
     *
     * When a shopper clicks a category (block by block through the nav or a
     * listing page), Magento resolves the category-specific URL rewrite and
     * registers it as 'current_category'; using that path means the crumb
     * matches the category actually clicked, instead of always expanding into
     * whichever subcategory the product happens to also be assigned to.
     * Arrivals with no such context - search results, direct/bookmarked
     * links - still get the full trail via the deepest assigned category, for
     * the same reason the FBT block picks it: a product's first/shallowest
     * category tends to be a broad bucket ("Outdoor Gear"), while the deepest
     * is the narrowest and gives the most informative trail.
     *
     * @return Category[]
     */
    private function getCategoryTrail(\Magento\Catalog\Model\Product $product): array
    {
        $currentCategory = $this->registry->registry('current_category');
        if ($currentCategory instanceof Category && $currentCategory->getId()) {
            return $this->getTrailFromPath((string) $currentCategory->getPath());
        }

        $categoryIds = $product->getCategoryIds();
        if (!$categoryIds) {
            return [];
        }

        $deepest = $this->categoryCollectionFactory->create()
            ->addAttributeToSelect(['name', 'path', 'level'])
            ->addAttributeToFilter('entity_id', ['in' => $categoryIds])
            ->addAttributeToFilter('is_active', 1)
            ->setOrder('level', 'DESC')
            ->setPageSize(1)
            ->getFirstItem();

        if (!$deepest->getId()) {
            return [];
        }

        return $this->getTrailFromPath((string) $deepest->getPath());
    }

    /**
     * Ancestors along a category path, root-first, self included.
     *
     * @return Category[]
     */
    private function getTrailFromPath(string $path): array
    {
        // path is "1/2/17/23" - every ancestor plus the category itself.
        $pathIds = array_filter(array_map('intval', explode('/', $path)));
        if (!$pathIds) {
            return [];
        }

        $trail = $this->categoryCollectionFactory->create()
            ->addAttributeToSelect(['name', 'url_key', 'url_path', 'level'])
            ->addAttributeToFilter('entity_id', ['in' => $pathIds])
            ->addAttributeToFilter('level', ['gteq' => self::FIRST_VISIBLE_LEVEL])
            ->addAttributeToFilter('is_active', 1)
            ->setOrder('level', 'ASC');

        return array_values($trail->getItems());
    }

    /**
     * schema.org BreadcrumbList. The stock template emitted this from JS; kept
     * here so replacing it does not cost the existing rich-result markup.
     */
    public function getJsonLd(): string
    {
        $product = $this->currentProduct->exists() ? $this->currentProduct->get() : null;
        $items = [];

        foreach ($this->getCrumbs() as $index => $crumb) {
            $url = $crumb['url'];
            if ($url === null && $product) {
                // The trailing product crumb has no link in the UI, but the
                // structured data still wants its canonical URL.
                $url = $product->getProductUrl();
            }
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['label'],
                'item' => $url,
            ];
        }

        return (string) json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ], JSON_UNESCAPED_SLASHES);
    }
}
