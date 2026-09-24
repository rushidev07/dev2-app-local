<?php
declare(strict_types=1);

namespace FalcoSense\Search\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Data extends AbstractHelper
{
    // Products priced above this are excluded from sync — placeholder/test pricing
    // (e.g. 99999) has repeatedly polluted the platform index and required manual
    // cleanup. Shared by the realtime observer path and the cron/full-sync paths
    // so the cap is enforced consistently everywhere a product can reach the platform.
    public const MAX_SYNC_PRICE = 90000.0;

    private const XML_PATH_FRONTEND_ENABLED  = 'smart_search/general/frontend_enabled';

    private const XML_PATH_HEADER_MOUNT = 'smart_search/general/header_mount';
    private const XML_PATH_ENABLED           = 'smart_search/general/enabled';
    private const XML_PATH_REALTIME_ENABLED  = 'smart_search/general/realtime_sync_enabled';
    private const XML_PATH_ENDPOINT_URL      = 'smart_search/general/endpoint_url';
    private const XML_PATH_SEARCH_URL        = 'smart_search/general/search_url';
    private const XML_PATH_PRODUCTS_PER_PAGE = 'smart_search/general/products_per_page';
    private const XML_PATH_API_KEY           = 'smart_search/general/api_key';
    private const XML_PATH_PLATFORM_STORE    = 'smart_search/general/platform_store_id';
    private const XML_PATH_WEBHOOK_SECRET    = 'smart_search/webhook/secret';
    private const XML_PATH_LAST_SYNC_AT      = 'smart_search/cron/last_sync_at';
    private const XML_PATH_FULL_SYNC_FLAG    = 'smart_search/cron/full_sync_requested';

    private const XML_PATH_IMAGE_COMPRESS_ENABLED     = 'smart_search/image_compress/enabled';
    private const XML_PATH_IMAGE_COMPRESS_LAST_RUN_AT = 'smart_search/image_compress/last_run_at';
    private const XML_PATH_IMAGE_COMPRESS_BATCH_SIZE  = 'smart_search/image_compress/batch_size';

    private const XML_PATH_SLIDER_1 = 'smart_search/sliders/slider_1_slug';
    private const XML_PATH_SLIDER_2 = 'smart_search/sliders/slider_2_slug';
    private const XML_PATH_SLIDER_3 = 'smart_search/sliders/slider_3_slug';
    private const XML_PATH_SLIDER_4 = 'smart_search/sliders/slider_4_slug';

    private const XML_PATH_NR_ENABLED       = 'smart_search/no_results_modal/enabled';
    private const XML_PATH_NR_TITLE         = 'smart_search/no_results_modal/title';
    private const XML_PATH_NR_SUBTITLE      = 'smart_search/no_results_modal/subtitle';
    private const XML_PATH_NR_HEADING       = 'smart_search/no_results_modal/section_heading';
    private const XML_PATH_NR_PRODUCT_COUNT = 'smart_search/no_results_modal/product_count';

    // Sub-second budget for the server-side PLP render-path call to
    // /api/v1/products — a slow response must be abandoned, not waited on,
    // since it's blocking the page response itself (unlike the client-side
    // fetch() in results.phtml, which can afford to be slower).
    private const XML_PATH_PLP_TIMEOUT_MS = 'smart_search/plp/platform_timeout_ms';

    /* Per-store presentation values the PLP components read off
       window.FalcoSense.config. These were literals inside the templates —
       Everest's sellers, Everest's placeholder image — which made the module
       unusable on any other storefront without editing its source. */
    private const XML_PATH_PLP_FREE_SHIPPING_SELLERS = 'smart_search/plp/free_shipping_sellers';
    private const XML_PATH_PLP_DEFAULT_SELLER        = 'smart_search/plp/default_seller';
    private const XML_PATH_PLP_FALLBACK_IMAGE        = 'smart_search/plp/fallback_image';
    private const XML_PATH_PLP_CDN_BASE              = 'smart_search/plp/cdn_base';
    private const XML_PATH_PLP_SCROLL_OFFSET         = 'smart_search/plp/scroll_offset';

    private ScopeConfigInterface $config;
    private WriterInterface $configWriter;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->config       = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->storeManager = $storeManager;
    }

    public function isFrontendEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_FRONTEND_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Which header block the search overlay mounts onto: 'hyva', 'luma' or 'none'.
     *
     * Read by Observer\AddLayoutHandles, which turns it into a layout handle. See
     * Model\Config\Source\HeaderMount for why this is configured rather than
     * detected.
     */
    public function getHeaderMount(int|string|null $storeId = null): string
    {
        $value = (string) $this->config->getValue(
            self::XML_PATH_HEADER_MOUNT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        /* Empty on a store upgraded from a version without this setting — detect
           rather than guess. */
        return $value !== '' ? $value : \FalcoSense\Search\Model\Config\Source\HeaderMount::AUTO;
    }

    public function isEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isRealtimeSyncEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_REALTIME_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getEndpointUrl(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_ENDPOINT_URL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getSearchUrl(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_SEARCH_URL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getProductsPerPage(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_PRODUCTS_PER_PAGE, ScopeInterface::SCOPE_STORE, $storeId);
        return $val > 0 ? $val : 12;
    }

    public function getPlpPlatformTimeoutMs(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_PLP_TIMEOUT_MS, ScopeInterface::SCOPE_STORE, $storeId);
        return $val > 0 ? $val : 500;
    }

    /**
     * Sellers whose products display a "FREE Shipping" badge.
     *
     * Stored as a comma-separated list because the alternative — a repeatable
     * admin row set — needs a backend model and a table, for a value most
     * stores will set once and never revisit.
     *
     * @return string[]
     */
    public function getPlpFreeShippingSellers(int|string|null $storeId = null): array
    {
        $raw = (string) $this->config->getValue(
            self::XML_PATH_PLP_FREE_SHIPPING_SELLERS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (trim($raw) === '') {
            return [];
        }

        $sellers = array_map('trim', explode(',', $raw));

        return array_values(array_filter($sellers, static fn (string $s): bool => $s !== ''));
    }

    public function getPlpDefaultSeller(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(
            self::XML_PATH_PLP_DEFAULT_SELLER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Shown in place of a product image that is missing or fails to load.
     *
     * Empty is a valid answer — the components fall back to rendering nothing
     * rather than a broken-image icon — but a store should set it.
     */
    public function getPlpFallbackImage(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(
            self::XML_PATH_PLP_FALLBACK_IMAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getPlpCdnBase(int|string|null $storeId = null): string
    {
        return rtrim((string) $this->config->getValue(
            self::XML_PATH_PLP_CDN_BASE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ), '/');
    }

    /**
     * Pixels to leave clear when scrolling results into view — a storefront with
     * a sticky header needs the results to stop below it, not underneath it.
     */
    public function getPlpScrollOffset(int|string|null $storeId = null): int
    {
        return max(0, (int) $this->config->getValue(
            self::XML_PATH_PLP_SCROLL_OFFSET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getEventsEndpointUrl(int|string|null $storeId = null): string
    {
        return $this->buildPlatformUrl('/api/v1/events', $storeId);
    }

    /**
     * Rebuilds the configured endpoint URL's scheme/host/port with a different path.
     * Returns '' if no endpoint is configured.
     */
    public function buildPlatformUrl(string $path, int|string|null $storeId = null): string
    {
        $endpoint = $this->getEndpointUrl($storeId);
        if (!$endpoint) {
            return '';
        }
        $parts = parse_url($endpoint);
        $base  = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base . $path;
    }

    public function getApiKey(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getPlatformStoreId(int|string|null $storeId = null): int
    {
        // Dynamic calculation matching FourSeasons approach
        $stores   = $this->storeManager->getStores(false);
        $storeIds = array_keys($stores);
        sort($storeIds);

        if ($storeId === null) {
            $storeId = (int) $this->storeManager->getStore()->getId();
        }

        $position = array_search((int) $storeId, $storeIds);
        if ($position !== false) {
            return (int) $position + 1;
        }

        // Fallback to config value
        return (int) ($this->config->getValue(self::XML_PATH_PLATFORM_STORE, ScopeInterface::SCOPE_STORE, $storeId) ?: 1);
    }

    public function getWebhookSecret(): string
    {
        return (string) $this->config->getValue(self::XML_PATH_WEBHOOK_SECRET, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function getLastSyncAt(): ?string
    {
        $value = $this->config->getValue(self::XML_PATH_LAST_SYNC_AT, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        return ($value && $value !== '') ? (string) $value : null;
    }

    public function setLastSyncAt(string $datetime): void
    {
        $this->configWriter->save(self::XML_PATH_LAST_SYNC_AT, $datetime, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function isFullSyncRequested(): bool
    {
        return (bool) $this->config->getValue(self::XML_PATH_FULL_SYNC_FLAG, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function requestFullSync(): void
    {
        $this->configWriter->save(self::XML_PATH_FULL_SYNC_FLAG, 1, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function clearFullSyncFlag(): void
    {
        $this->configWriter->save(self::XML_PATH_FULL_SYNC_FLAG, 0, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function isImageCompressEnabled(): bool
    {
        return (bool) $this->config->getValue(self::XML_PATH_IMAGE_COMPRESS_ENABLED, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /** Delta cursor — the newest `updated_at` seen by the last successful run, or null before the first run. */
    public function getImageCompressLastRunAt(): ?string
    {
        $value = $this->config->getValue(self::XML_PATH_IMAGE_COMPRESS_LAST_RUN_AT, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        return ($value && $value !== '') ? (string) $value : null;
    }

    public function setImageCompressLastRunAt(string $datetime): void
    {
        $this->configWriter->save(self::XML_PATH_IMAGE_COMPRESS_LAST_RUN_AT, $datetime, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function getImageCompressBatchSize(): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_IMAGE_COMPRESS_BATCH_SIZE, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        return $val > 0 ? $val : 200;
    }

    public function isNoResultsModalEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_NR_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalTitle(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_TITLE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalSubtitle(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_SUBTITLE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalHeading(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_HEADING, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsProductCount(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_NR_PRODUCT_COUNT, ScopeInterface::SCOPE_STORE, $storeId);
        return max(1, min(12, $val ?: 6));
    }

    /**
     * Returns all configured slider slugs (1-4), skipping empty ones.
     *
     * @return string[]
     */
    public function getSliderSlugs(int|string|null $storeId = null): array
    {
        $paths  = [self::XML_PATH_SLIDER_1, self::XML_PATH_SLIDER_2, self::XML_PATH_SLIDER_3, self::XML_PATH_SLIDER_4];
        $result = [];
        foreach ($paths as $path) {
            $v = trim((string) $this->config->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
            if ($v !== '') {
                $result[] = $v;
            }
        }
        return $result;
    }
}
