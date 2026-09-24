<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use FalcoSense\Search\Helper\Data as SmartSearchHelper;
use FalcoSense\Search\Block\Plp\PresentationConfigTrait;
use FalcoSense\Search\Service\SearchTokenService;
use FalcoSense\Search\Api\PlpDataProviderInterface;
use FalcoSense\Search\Model\Plp\PageContext;
use FalcoSense\Search\Model\Plp\PlpResult;

class Category extends Template
{
    use PresentationConfigTrait;

    private SmartSearchHelper  $helper;
    private LayerResolver      $layerResolver;
    private SearchTokenService $tokenService;
    private PageContext $pageContext;
    private PlpDataProviderInterface $plpProvider;
    private ?PlpResult $plpResult = null;
    private bool $plpResolved = false;

    /**
     * Constructor arguments are APPEND-ONLY past `array $data`.
     *
     * Host storefronts subclass this block — Everest's Ahy_PlpRevamp does — and
     * those subclasses forward a fixed argument list to parent::__construct().
     * Inserting a new dependency before $data silently rebinds their $data array
     * onto a typed parameter, and every one of them dies with a TypeError that
     * names this file rather than theirs. That is exactly how PlpRevamp broke
     * when PageContext and PlpDataProviderInterface were first added here.
     *
     * So: $data keeps its historical position, new dependencies go after it and
     * are nullable, and a null falls back to the object manager. Magento's own
     * core classes use this pattern for the same reason. A subclass written
     * against any past signature keeps working; DI passes the real instances
     * when it constructs this class directly.
     */
    public function __construct(
        Context            $context,
        SmartSearchHelper  $helper,
        LayerResolver      $layerResolver,
        SearchTokenService $tokenService,
        array              $data = [],
        ?PageContext       $pageContext = null,
        ?PlpDataProviderInterface $plpProvider = null
    ) {
        parent::__construct($context, $data);
        $this->helper        = $helper;
        $this->layerResolver = $layerResolver;
        $this->tokenService  = $tokenService;
        $this->pageContext   = $pageContext ?? ObjectManager::getInstance()->get(PageContext::class);
        $this->plpProvider   = $plpProvider ?? ObjectManager::getInstance()->get(PlpDataProviderInterface::class);
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
