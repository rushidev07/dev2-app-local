<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use FalcoSense\Search\Helper\Data as SmartSearchHelper;
use FalcoSense\Search\Service\SearchTokenService;
use FalcoSense\Search\Api\PlpDataProviderInterface;
use FalcoSense\Search\Model\Plp\PageContext;
use FalcoSense\Search\Model\Plp\PlpResult;

class Category extends Template
{
    private SmartSearchHelper  $helper;
    private LayerResolver      $layerResolver;
    private SearchTokenService $tokenService;
    private PageContext $pageContext;
    private PlpDataProviderInterface $plpProvider;
    private ?PlpResult $plpResult = null;
    private bool $plpResolved = false;

    public function __construct(
        Context            $context,
        SmartSearchHelper  $helper,
        LayerResolver      $layerResolver,
        SearchTokenService $tokenService,
        PageContext        $pageContext,
        PlpDataProviderInterface $plpProvider,
        array              $data = []
    ) {
        parent::__construct($context, $data);
        $this->helper        = $helper;
        $this->layerResolver = $layerResolver;
        $this->tokenService  = $tokenService;
        $this->pageContext   = $pageContext;
        $this->plpProvider   = $plpProvider;
    }

    /**
     * The server-rendered view of this category's product grid, for
     * whatever page/sort the URL specifies — unlike search, this isn't
     * restricted to the canonical view (see PageContext::buildCategoryQuery).
     * Null when the platform returned nothing usable, or there's no
     * resolvable category. Memoized since the template calls this more than
     * once (the SSR grid markup, then the embedded JSON payload).
     */
    public function getPlpResult(): ?PlpResult
    {
        if ($this->plpResolved) {
            return $this->plpResult;
        }
        $this->plpResolved = true;

        $query = $this->pageContext->buildCategoryQuery();
        if ($query === null) {
            return null;
        }

        $result = $this->plpProvider->fetch($query);
        $this->plpResult = $result->isUsable() ? $result : null;

        return $this->plpResult;
    }

    public function getSearchApiUrl(): string
    {
        $url = $this->helper->getSearchUrl();
        return $url ?: '';
    }

    public function getApiKey(): string
    {
        return $this->helper->getApiKey();
    }

    public function getSearchToken(int $magentoStoreId = 0): string
    {
        return $this->tokenService->getToken($magentoStoreId);
    }

    public function getProductsApiUrl(): string
    {
        $endpoint = $this->helper->getEndpointUrl();
        if (!$endpoint) {
            return '';
        }
        $parts = parse_url($endpoint);
        $base  = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base . '/api/v1/products';
    }

    public function getPlatformStoreId(): int
    {
        return $this->helper->getPlatformStoreId();
    }

    public function getCategoryName(): string
    {
        try {
            $layer    = $this->layerResolver->get();
            $category = $layer->getCurrentCategory();
            return (string) ($category ? $category->getName() : '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getCategoryId(): int
    {
        try {
            $layer    = $this->layerResolver->get();
            $category = $layer->getCurrentCategory();
            return (int) ($category ? $category->getId() : 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
