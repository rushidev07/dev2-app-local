<?php
declare(strict_types=1);

namespace FalcoSense\Search\Console\Command;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\DisabledParentResolver;
use FalcoSense\Search\Service\DuplicateSkuResolver;
use FalcoSense\Search\Service\ProductImageCompressionService;
use FalcoSense\Search\Service\ProductSyncService;
use FalcoSense\Search\Service\SyncLockManager;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FullSyncCommand extends Command
{
    private const BATCH_SIZE = 300;

    // Reconciliation safety net (see reconcileDeletedProducts()) — refuse to
    // auto-delete more than this many products from the platform in one pass,
    // regardless of what fraction of the catalog that is. A percentage-based
    // cap scales the wrong way for a catalog that runs frequent large,
    // legitimate cleanups (e.g. used/discontinued inventory) — a genuine few-
    // thousand-product deletion can exceed 10% of a smaller catalog and get
    // blocked forever. An absolute count is a much clearer bar: still catches
    // a bug or misconfiguration (wrong store scope, a broken query, an
    // unreachable/wrong API endpoint) producing an implausibly large diff,
    // without punishing a real bulk cleanup just because the catalog is small.
    private const RECONCILE_MAX_DELETE_COUNT = 10000;

    // Same lock name smartsearch:image:compress uses standalone — sharing it means
    // a manual run and this chained run can never process images concurrently.
    private const IMAGE_LOCK_NAME    = 'falcosense_search_image_compress';
    private const IMAGE_LOCK_TIMEOUT = 3600;
    private const IMAGE_LIMIT        = 500;

    public function __construct(
        private readonly Data                            $helper,
        private readonly ProductSyncService              $syncService,
        private readonly CollectionFactory               $collectionFactory,
        private readonly SyncLockManager                 $lockManager,
        private readonly StoreManagerInterface           $storeManager,
        private readonly DisabledParentResolver          $disabledParentResolver,
        private readonly DuplicateSkuResolver            $duplicateSkuResolver,
        private readonly LoggerInterface                 $logger,
        private readonly LoggerInterface                 $imageCompressLogger,
        private readonly ProductImageCompressionService  $imageCompressionService,
        private readonly LockManagerInterface             $imageLockManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('smartsearch:sync:full')
             ->setDescription('Sync products to the search platform.')
             ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore cursor — send all products')
             ->addOption('store', null, InputOption::VALUE_OPTIONAL, 'Magento store ID (0 = all stores)', 0)
             ->addOption('page', null, InputOption::VALUE_OPTIONAL, 'Page number to start from (resume after an interrupted run)', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeId   = (int) $input->getOption('store');
        $force     = (bool) $input->getOption('force');
        $startPage = max(1, (int) $input->getOption('page'));

        // Resolve which stores to sync
        $storeIds = $storeId > 0 ? [$storeId] : $this->getConfiguredStoreIds();

        if (empty($storeIds)) {
            $msg = 'No stores with API key configured. Aborting.';
            $output->writeln("<error>[SmartSearch] {$msg}</error>");
            $this->logger->error($msg);
            return 1;
        }

        if (!$this->lockManager->acquireOrTakeOver('command')) {
            $info = $this->lockManager->getLockInfo();
            $msg = sprintf('Sync already running (via %s, started %s).', $info['source'] ?? 'unknown', $info['started'] ?? 'unknown');
            $output->writeln("<error>[SmartSearch] {$msg}</error>");
            $this->logger->error($msg);
            return 1;
        }

        $lockManager = $this->lockManager;
        register_shutdown_function(static fn() => $lockManager->release());

        $lastSyncAt    = $force ? null : $this->helper->getLastSyncAt();
        $syncStartedAt = date('Y-m-d H:i:s');
        $totalIndexed = 0;
        $totalFailed  = 0;
        $totalDeleted = 0;
        $start        = microtime(true);

        $startMsg = sprintf('Syncing stores: %s — mode: %s%s',
            implode(', ', $storeIds),
            $force ? 'force-full' : 'delta since ' . ($lastSyncAt ?? 'beginning'),
            $startPage > 1 ? ", starting at page {$startPage}" : ''
        );
        $output->writeln("<info>[SmartSearch] {$startMsg}</info>");
        $this->logger->info('=== Full sync STARTED === ' . $startMsg);

        $wasAborted = false;

        try {
            foreach ($storeIds as $sid) {
                $output->writeln("<info>[SmartSearch] → Store {$sid}</info>");
                [$indexed, $failed, $aborted, $deleted] = $this->syncStore($sid, $lastSyncAt, $output, $startPage);
                $totalIndexed += $indexed;
                $totalFailed  += $failed;
                $totalDeleted += $deleted;
                if ($aborted) {
                    $wasAborted = true;
                    break;
                }
            }

            $elapsed = round(microtime(true) - $start, 1);

            // Resuming from a specific page means earlier pages were never (re)covered
            // by this run — advancing the cursor here would make the next delta sync
            // skip them, so only move it forward when this run walked the whole catalog.
            // An aborted run (e.g. the admin "Stop Sync" button, which just releases the
            // lock) can reach here with $totalFailed still 0 — zero *failures* isn't the
            // same as *completion*, so the cursor must not advance and the completeness-
            // dependent image-compression chain below must not fire either.
            $completedCleanly = $totalFailed === 0 && !$wasAborted;

            if ($completedCleanly && $startPage === 1) {
                $this->helper->setLastSyncAt($syncStartedAt);
            }

            $msg = "Synced {$totalIndexed} products in {$elapsed}s"
                . ($totalDeleted ? ", deleted (disabled/OOS): {$totalDeleted}" : '')
                . ($totalFailed ? ", failed: {$totalFailed}" : '')
                . ($wasAborted ? ', aborted' : '') . '.';
            $this->lockManager->writeResult($completedCleanly, $msg);
            $output->writeln("<info>[SmartSearch] Done — {$msg}</info>");

            if ($completedCleanly) {
                $this->logger->info('=== Full sync FINISHED (success) === ' . $msg);
            } else {
                $this->logger->error('=== Full sync FINISHED (' . ($wasAborted ? 'aborted' : 'with failures') . ') === ' . $msg);
            }

            // Chain image compression only after a full, clean, uninterrupted sync.
            if ($completedCleanly) {
                $this->runImageCompression($output);
            }

            return $completedCleanly ? 0 : 1;

        } finally {
            $this->lockManager->release();
        }
    }

    /**
     * Chained after a successful full sync (see execute()). Mirrors
     * ImageCompressCommand's own logic exactly, sharing its lock name so a manual
     * `smartsearch:image:compress` run and this chained run can never overlap.
     * A failure here never changes smartsearch:sync:full's own exit code — the
     * sync already succeeded; this failure is fully visible in its own error log.
     */
    private function runImageCompression(OutputInterface $output): void
    {
        $this->imageCompressLogger->info('=== Image compression STARTED (chained from full sync) ===');

        if (!$this->imageLockManager->lock(self::IMAGE_LOCK_NAME, self::IMAGE_LOCK_TIMEOUT)) {
            $msg = 'Already running elsewhere — skipping this chained run.';
            $output->writeln("<comment>[SmartSearch] Image compression: {$msg}</comment>");
            $this->imageCompressLogger->info($msg);
            return;
        }

        try {
            $since = $this->helper->getImageCompressLastRunAt();
            $stats = $this->imageCompressionService->run($since, self::IMAGE_LIMIT);

            $summary = sprintf(
                'checked=%d compressed=%d skipped=%d missing=%d failed=%d',
                $stats['checked'], $stats['compressed'], $stats['skipped'], $stats['missing'], $stats['failed']
            );
            $output->writeln("<info>[SmartSearch] Image compression done — {$summary}</info>");

            if ($stats['truncated']) {
                $note = sprintf('Backlog larger than limit=%d — cursor not advanced, next run continues from the same point.', self::IMAGE_LIMIT);
                $output->writeln("<comment>[SmartSearch] {$note}</comment>");
                $this->imageCompressLogger->info('=== Image compression FINISHED (truncated) === ' . $summary . '. ' . $note);
            } else {
                if ($stats['newestUpdatedAt'] !== null) {
                    $this->helper->setImageCompressLastRunAt($stats['newestUpdatedAt']);
                }
                if ($stats['failed'] > 0) {
                    $this->imageCompressLogger->error('=== Image compression FINISHED (with failures) === ' . $summary);
                } else {
                    $this->imageCompressLogger->info('=== Image compression FINISHED (success) === ' . $summary);
                }
            }
        } catch (\Throwable $e) {
            $msg = 'Image compression crashed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            $output->writeln("<error>[SmartSearch] {$msg}</error>");
            $this->imageCompressLogger->error('=== Image compression FINISHED (crashed) === ' . $msg);
        } finally {
            $this->imageLockManager->unlock(self::IMAGE_LOCK_NAME);
        }
    }

    private function syncStore(int $storeId, ?string $lastSyncAt, OutputInterface $output, int $startPage = 1): array
    {
        $page = $startPage;
        $indexed = 0;
        $failed  = 0;

        // A configurable child keeps its own independent status attribute --
        // disabling the parent does NOT disable its children. Without this,
        // an individually-still-Enabled child would sync on its own even
        // though its parent (the only thing actually shown/sold) is Disabled.
        $orphanedChildIds = $this->disabledParentResolver->getOrphanedChildIds($storeId);
        if (!empty($orphanedChildIds)) {
            $output->writeln(sprintf('<comment>[SmartSearch] Excluding %d child product(s) of a disabled parent.</comment>', count($orphanedChildIds)));
        }

        // Overpriced configurable family — parent and/or a child priced above the
        // sync cap. The whole family is excluded together (see
        // DisabledParentResolver::getOverpricedConfigurableFamilyIds()).
        $overpricedFamilyIds = $this->disabledParentResolver->getOverpricedConfigurableFamilyIds(Data::MAX_SYNC_PRICE, $storeId);
        if (!empty($overpricedFamilyIds)) {
            $output->writeln(sprintf(
                '<comment>[SmartSearch] Excluding %d product(s) from an overpriced configurable family (>%s).</comment>',
                count($overpricedFamilyIds), Data::MAX_SYNC_PRICE
            ));
        }

        // Configurable parent where every child is out of stock. Magento's own
        // stock item for a configurable parent is virtually always "in stock"
        // regardless of its children, so this can't be caught by either the main
        // loop's own-stock filter or the disabled/OOS sweep's own-stock filter
        // below — see DisabledParentResolver::getConfigurableParentIdsWithNoInStockChild().
        // Excluded from the main loop here; actually deleted in a dedicated pass
        // after the disabled/OOS sweep (children are already handled normally,
        // since each child's own stock item correctly shows out-of-stock).
        $fullyOosParentIds = $this->disabledParentResolver->getConfigurableParentIdsWithNoInStockChild($storeId);
        if (!empty($fullyOosParentIds)) {
            $output->writeln(sprintf('<comment>[SmartSearch] Excluding %d configurable parent(s) with no in-stock child.</comment>', count($fullyOosParentIds)));
        }

        $excludedIdSet = array_flip(array_unique(array_merge($orphanedChildIds, $overpricedFamilyIds, $fullyOosParentIds)));

        // Same overpriced-family set, kept separate for the disabled/OOS sweep below —
        // that sweep does not exclude orphaned children (a child of a disabled parent
        // can itself still be individually disabled/OOS and legitimately needs the
        // unavailable-marking upsert), only overpriced ones.
        $overpricedIdSet = array_flip($overpricedFamilyIds);

        // Superseded ancestor SKUs (e.g. "coconut" when "coconut-1" also exists) —
        // excluded from being SYNCED, same as overpriced families: never actively
        // deleted if already on the platform, only kept from being re-added by a
        // future full sync. See DuplicateSkuResolver's docblock for the guards
        // (independently-visible only, suffix capped at 1-3 digits) that keep this
        // from ever misclassifying a real, distinct product as a duplicate ancestor.
        // Single collection pass for both forms — see getSupersededSkusAndIds()'s
        // docblock; calling getSupersededSkus() and getSupersededProductIds()
        // separately would scan the full catalog twice for the same result.
        $superseded           = $this->duplicateSkuResolver->getSupersededSkusAndIds($storeId);
        $supersededSkus       = $superseded['skus'];
        $supersededProductIds = $superseded['ids'];
        $supersededSkuSet     = array_flip($supersededSkus);
        if (!empty($supersededSkus)) {
            $output->writeln(sprintf('<comment>[SmartSearch] Excluding %d superseded ancestor SKU(s).</comment>', count($supersededSkus)));
        }

        do {
            if (!$this->lockManager->isLocked()) {
                $output->writeln('<comment>[SmartSearch] Lock removed — aborting.</comment>');
                return [$indexed, $failed, true, 0];
            }

            $rawProducts = array_values($this->buildCollection($lastSyncAt, $storeId, $page)->getItems());
            if (empty($rawProducts)) break;

            $products = (empty($excludedIdSet) && empty($supersededSkuSet))
                ? $rawProducts
                : array_values(array_filter($rawProducts, static fn($p) =>
                    !isset($excludedIdSet[(int) $p->getId()]) && !isset($supersededSkuSet[$p->getSku()])
                ));

            if (empty($products)) {
                $page++;
                continue;
            }

            $count = $attempt = 0;
            $count = -3;
            while ($count === -3 && $attempt <= 3) {
                if ($attempt > 0) sleep(min(60, 10 * $attempt));
                $count = $this->syncService->syncBatch($products, $storeId);
                $attempt++;
            }

            if ($count === -1) {
                $output->writeln('<error>[SmartSearch] Invalid API key or connection error. Aborting.</error>');
                $this->logger->error(sprintf('Invalid API key or connection error for store %d — aborting.', $storeId));
                $this->lockManager->writeResult(false, 'Connection failed or invalid API key.');
                return [$indexed, $failed + count($products), false, 0];
            }
            if ($count === -2) {
                $output->writeln('<comment>[SmartSearch] Product limit reached.</comment>');
                break;
            }
            if ($count === -3) {
                $failed += count($products);
                $page++;
                continue;
            }

            $indexed += $count;
            $output->writeln("  Page {$page} → {$count} products");
            $page++;
        } while (count($rawProducts) === self::BATCH_SIZE);

        // ── Disabled/OOS sweep ───────────────────────────────────────────────
        // Closes the gap real-time observers can't cover: bulk/API updates that
        // write status/stock directly and never dispatch catalog_product_save_after
        // / cataloginventory_stock_item_save_after. Finds anything disabled/OOS
        // since the cursor (or, on a full sync, everything currently disabled/OOS,
        // i.e. --force) and DELETES it from the platform (both OpenSearch and SQL).
        // Previously this upserted the disabled/OOS status instead of deleting, as a
        // workaround for the delete endpoint timing out under load (each call was a
        // synchronous OpenSearch round-trip inside ProductIngestService::
        // deleteProducts()'s foreach — see the Aug 2026 sweep-503 investigation).
        // That endpoint is now batched (IndexingService::bulkDelete() + batched SQL),
        // so real deletion is safe again — see deleteBatch() below. Retries transient
        // failures the same way the main sync loop above does.
        $deleted    = 0;
        $deletePage = 1;
        do {
            if (!$this->lockManager->isLocked()) {
                $output->writeln('<comment>[SmartSearch] Lock removed — aborting.</comment>');
                return [$indexed, $failed, true, $deleted];
            }

            $delCollection = $this->buildDisabledOrOosCollection($lastSyncAt, $storeId, $deletePage);
            $rawOosProducts = array_values($delCollection->getItems());

            if (empty($rawOosProducts)) break;

            // Catches the configurable-family case buildDisabledOrOosCollection()'s own
            // SQL price filter can't: a parent with no price of its own but an overpriced
            // child variant. Also excludes superseded ancestor SKUs (same set the main
            // loop uses) — a disabled/OOS ancestor must not be re-synced either.
            $oosProducts = (empty($overpricedIdSet) && empty($supersededSkuSet))
                ? $rawOosProducts
                : array_values(array_filter($rawOosProducts, static fn($p) =>
                    !isset($overpricedIdSet[(int) $p->getId()]) && !isset($supersededSkuSet[$p->getSku()])
                ));

            if (empty($oosProducts)) {
                $deletePage++;
                continue;
            }

            // Real delete again (not upsert-as-unavailable) — safe now that the
            // platform's delete endpoint batches both the OpenSearch removal and the
            // SQL rows instead of doing one synchronous round-trip per product (see
            // IndexingService::bulkDelete() / ProductIngestService::deleteProducts(),
            // fixed Aug 2026). That per-product loop was the actual reason deletion
            // used to time out — upserting was a workaround for that, not the goal.
            $delIds = array_map(static fn($p) => (int) $p->getId(), $oosProducts);

            $delCount = $attempt = 0;
            $delCount = -3;
            while ($delCount === -3 && $attempt <= 3) {
                if ($attempt > 0) sleep(min(60, 10 * $attempt));
                $delCount = $this->syncService->deleteBatch($delIds, $storeId);
                $attempt++;
            }

            if ($delCount === -1) {
                $output->writeln('<error>[SmartSearch] Disabled/OOS sweep: connection failed or invalid API key. Aborting.</error>');
                $this->logger->error(sprintf('Disabled/OOS sweep: connection failed or invalid API key for store %d — aborting.', $storeId));
                $this->lockManager->writeResult(false, 'Connection failed or invalid API key during disabled/OOS sweep.');
                return [$indexed, $failed, false, $deleted];
            }
            if ($delCount === -3) {
                $failed += count($oosProducts);
                $deletePage++;
                continue;
            }

            $deleted += max(0, $delCount);
            $output->writeln(sprintf('  Disabled/OOS sweep: deleted %d product(s)', max(0, $delCount)));
            $deletePage++;
        // Pagination is driven by the RAW (pre-filter) page count — a page that's
        // entirely overpriced still counts as a full page, so the next page must
        // still be checked; otherwise coverage silently stops early.
        } while (count($rawOosProducts) === self::BATCH_SIZE);

        // Overpriced disabled/OOS products are excluded above (own-price filter in
        // buildDisabledOrOosCollection() + $overpricedIdSet for the family/variant
        // case) — this sweep never re-syncs them, regardless of what's already on
        // the platform.

        // ── Fully out-of-stock configurable parents ─────────────────────────
        // Deleted directly here rather than via buildDisabledOrOosCollection() above,
        // since that sweep's own-stock filter can never see these (see
        // $fullyOosParentIds above / DisabledParentResolver::
        // getConfigurableParentIdsWithNoInStockChild()'s docblock). Their children
        // don't need this — each child's own stock item already correctly shows
        // out-of-stock, so both the main loop and the sweep above already handle
        // them normally.
        foreach (array_chunk($fullyOosParentIds, self::BATCH_SIZE) as $chunk) {
            if (!$this->lockManager->isLocked()) {
                $output->writeln('<comment>[SmartSearch] Lock removed — aborting.</comment>');
                return [$indexed, $failed, true, $deleted];
            }

            $delCount = $attempt = 0;
            $delCount = -3;
            while ($delCount === -3 && $attempt <= 3) {
                if ($attempt > 0) sleep(min(60, 10 * $attempt));
                $delCount = $this->syncService->deleteBatch($chunk, $storeId);
                $attempt++;
            }

            if ($delCount === -1) {
                $output->writeln('<error>[SmartSearch] Fully-OOS configurable sweep: connection failed or invalid API key. Aborting.</error>');
                $this->logger->error(sprintf('Fully-OOS configurable sweep: connection failed or invalid API key for store %d — aborting.', $storeId));
                $this->lockManager->writeResult(false, 'Connection failed or invalid API key during fully-OOS configurable sweep.');
                return [$indexed, $failed, false, $deleted];
            }
            if ($delCount === -3) {
                $failed += count($chunk);
                continue;
            }

            $deleted += max(0, $delCount);
            $output->writeln(sprintf('  Fully-OOS configurable sweep: deleted %d parent product(s)', max(0, $delCount)));
        }

        // ── Overpriced families / superseded SKUs still on the platform ─────
        // Both sets above are excluded from being synced (never upserted, never
        // re-synced by the disabled/OOS sweep) — but an earlier sync may have
        // already put them on the platform, back when the price was still fine
        // or the higher-numbered duplicate didn't exist yet. Now that they're
        // excluded, remove them if present. Uses the exact same ID lists already
        // trusted above to exclude these products from sync — no new logic, just
        // also acting on removal instead of only suppression. Deleting an ID that
        // was never actually on the platform is a harmless no-op on the platform
        // side, so this can never remove the wrong product.
        $excludedNowIds = array_values(array_unique(array_merge($overpricedFamilyIds, $supersededProductIds)));
        foreach (array_chunk($excludedNowIds, self::BATCH_SIZE) as $chunk) {
            if (!$this->lockManager->isLocked()) {
                $output->writeln('<comment>[SmartSearch] Lock removed — aborting.</comment>');
                return [$indexed, $failed, true, $deleted];
            }

            $delCount = $attempt = 0;
            $delCount = -3;
            while ($delCount === -3 && $attempt <= 3) {
                if ($attempt > 0) sleep(min(60, 10 * $attempt));
                $delCount = $this->syncService->deleteBatch($chunk, $storeId);
                $attempt++;
            }

            if ($delCount === -1) {
                $output->writeln('<error>[SmartSearch] Overpriced/superseded sweep: connection failed or invalid API key. Aborting.</error>');
                $this->logger->error(sprintf('Overpriced/superseded sweep: connection failed or invalid API key for store %d — aborting.', $storeId));
                $this->lockManager->writeResult(false, 'Connection failed or invalid API key during overpriced/superseded sweep.');
                return [$indexed, $failed, false, $deleted];
            }
            if ($delCount === -3) {
                $failed += count($chunk);
                continue;
            }

            $deleted += max(0, $delCount);
            $output->writeln(sprintf('  Overpriced/superseded sweep: deleted %d product(s)', max(0, $delCount)));
        }

        // ── Reconciliation: products removed from Magento's database entirely ──
        // Every check above works by asking Magento "of the products that still
        // exist, which ones need action?" — none of them can ever detect a
        // product that was deleted directly from the database (or even deleted
        // normally through Magento, since there is no delete-event listener at
        // all for this module). This closes that gap: it asks the platform what
        // it currently has, compares that against what Magento's database
        // actually still has (a plain existence check, independent of every
        // other filter/exclusion above), and deletes whatever the platform has
        // that Magento doesn't recognize at all anymore. Guarded by
        // RECONCILE_MAX_DELETE_COUNT so a bug on either side can never cause
        // a mass deletion — see reconcileDeletedProducts().
        [$reconciledDeleted, $reconcileFailed] = $this->reconcileDeletedProducts($storeId, $output);
        $deleted += $reconciledDeleted;
        $failed  += $reconcileFailed;

        return [$indexed, $failed, false, $deleted];
    }

    /**
     * Deletes platform products that no longer exist in Magento at all —
     * see the call site's comment in syncStore() for why this is needed.
     *
     * Safety guarantees, in order:
     *  1. If Magento itself reports zero products for this store, treat that
     *     as a probable fault (wrong scope, broken query) rather than a truly
     *     empty catalog, and do nothing.
     *  2. If the platform's current list can't be fetched (network error,
     *     bad response, missing config), do nothing — never act without
     *     confirmed, fresh data from both sides.
     *  3. If the resulting diff would delete more than
     *     RECONCILE_MAX_DELETE_COUNT products in one pass, refuse and log
     *     loudly instead of deleting — that scale is far more likely a bug
     *     than genuine mass deletion.
     * Only once all three pass does it delete, using the same batched
     * deleteBatch() + retry + lock-check pattern as every other sweep above.
     *
     * @return array{0: int, 1: int} [deletedCount, failedCount]
     */
    private function reconcileDeletedProducts(int $storeId, OutputInterface $output): array
    {
        $magentoIds = $this->getMagentoExistingProductIds($storeId);

        if (empty($magentoIds)) {
            $output->writeln('<comment>[SmartSearch] Reconciliation: Magento reports 0 products for this store — skipping (more likely a fault than a genuinely empty catalog).</comment>');
            $this->logger->warning(sprintf('Reconciliation: Magento returned 0 product IDs for store %d — skipped.', $storeId));
            return [0, 0];
        }

        $platformIds = $this->syncService->getPlatformProductIds($storeId);

        if ($platformIds === null) {
            $output->writeln('<comment>[SmartSearch] Reconciliation: could not fetch the platform\'s current product list — skipping this run.</comment>');
            return [0, 0];
        }

        if (empty($platformIds)) {
            return [0, 0];
        }

        $magentoIdSet = array_flip($magentoIds);
        $staleIds     = array_values(array_filter($platformIds, static fn($id) => !isset($magentoIdSet[$id])));

        if (empty($staleIds)) {
            return [0, 0];
        }

        if (count($staleIds) > self::RECONCILE_MAX_DELETE_COUNT) {
            $msg = sprintf(
                'Reconciliation: SKIPPED — would delete %d of %d platform product(s), over the %d-product safety limit for store %d. Investigate manually before deleting this many at once.',
                count($staleIds), count($platformIds), self::RECONCILE_MAX_DELETE_COUNT, $storeId
            );
            $output->writeln("<error>[SmartSearch] {$msg}</error>");
            $this->logger->error($msg);
            return [0, 0];
        }

        $output->writeln(sprintf('<comment>[SmartSearch] Reconciliation: found %d product(s) on the platform no longer present in Magento.</comment>', count($staleIds)));

        $deleted = 0;
        $failed  = 0;
        foreach (array_chunk($staleIds, self::BATCH_SIZE) as $chunk) {
            if (!$this->lockManager->isLocked()) {
                $output->writeln('<comment>[SmartSearch] Lock removed — aborting reconciliation.</comment>');
                break;
            }

            $delCount = $attempt = 0;
            $delCount = -3;
            while ($delCount === -3 && $attempt <= 3) {
                if ($attempt > 0) sleep(min(60, 10 * $attempt));
                $delCount = $this->syncService->deleteBatch($chunk, $storeId);
                $attempt++;
            }

            if ($delCount === -1) {
                $output->writeln('<error>[SmartSearch] Reconciliation: connection failed or invalid API key. Aborting.</error>');
                $this->logger->error(sprintf('Reconciliation: connection failed or invalid API key for store %d — aborting.', $storeId));
                break;
            }
            if ($delCount === -3) {
                $failed += count($chunk);
                continue;
            }

            $deleted += max(0, $delCount);
            $output->writeln(sprintf('  Reconciliation: deleted %d product(s) no longer in Magento', max(0, $delCount)));
        }

        return [$deleted, $failed];
    }

    /**
     * Every product ID Magento currently has for this store — no status,
     * price, or stock filter at all, deliberately independent of every
     * exclusion computed elsewhere in this class. A pure existence check:
     * "does this ID exist in Magento right now, in any state." Used only by
     * reconcileDeletedProducts() above.
     */
    private function getMagentoExistingProductIds(int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addStoreFilter($storeId);
        return array_map('strval', $collection->getAllIds());
    }

    /**
     * Products that are disabled OR out-of-stock — the inverse of buildCollection()'s
     * filter — used to sweep-DELETE anything a bulk/API update pushed into that state
     * without going through the real-time observers (which already delete on save —
     * see ProductSyncService::sync()). On a full sync ($lastSyncAt null, i.e. --force)
     * this deliberately has no updated_at floor, so it also catches anything that
     * became disabled/OOS before this sweep existed.
     *
     * Only 'sku' needs selecting — the products found here go to
     * ProductSyncService::deleteBatch(), which only needs each product's id (always
     * present) and, via syncStore()'s PHP-side ancestor filter, its sku.
     */
    private function buildDisabledOrOosCollection(?string $lastSyncAt, int $storeId, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToSelect(['sku']);

        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            ['stock_id' => 1],
            'left'
        );

        // status = disabled OR is_in_stock = 0 (left-joined, so NULL counts as "no stock row" too).
        // Price is filtered separately below, not folded into this OR — it must apply
        // to every row this collection returns, not be one more alternative in the OR.
        //
        // NOTE: must be ONE array where each element embeds its own 'attribute' key —
        // this is Magento's real multi-condition OR shape (see AbstractCollection::
        // addAttributeToFilter(), which does `$condition['attribute']` inside the loop).
        // The previous two-separate-arrays form — addFieldToFilter(['status','is_in_stock'],
        // [['eq'=>...],['eq'=>...]]) — silently threw a TypeError under PHP 8 ("Cannot
        // access offset of type string on string") every time this ran, meaning the
        // disabled/OOS sweep has not actually been running at all.
        $collection->addFieldToFilter([
            ['attribute' => 'status', 'eq' => Status::STATUS_DISABLED],
            ['attribute' => 'is_in_stock', 'eq' => 0],
        ]);

        // Overpriced exclusion — mirrors buildCollection()'s own-price check, so a
        // disabled/OOS product priced above the cap is never upserted by this sweep
        // either. NULL is allowed through for the same reason as buildCollection():
        // configurable parents routinely carry no price of their own. The per-child
        // (configurable-family) case is handled separately in syncStore(), via
        // $overpricedFamilyIds, since a bad CHILD price can hide behind a NULL or
        // perfectly fine parent-level price attribute here too.
        $collection->addAttributeToFilter('price', [
            ['null' => true],
            ['lteq' => Data::MAX_SYNC_PRICE],
        ]);

        if ($lastSyncAt !== null) {
            $collection->addAttributeToFilter('updated_at', ['gt' => $lastSyncAt]);
        }

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }

    /**
     * All store IDs that have an API key configured.
     */
    private function getConfiguredStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            $sid = (int) $store->getId();
            if ($this->helper->getApiKey($sid)) {
                $ids[] = $sid;
            }
        }
        return $ids;
    }

    private function buildCollection(?string $lastSyncAt, int $storeId, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);

        // Overpriced exclusion — placeholder/test pricing (e.g. 99999) must not sync.
        // NULL is allowed through: configurable parents routinely carry no price of
        // their own, deriving one from their cheapest child instead (see
        // ProductSyncService::normalize()) — that per-child check happens separately
        // via $excludedIdSet in syncStore(), since a bad CHILD price can hide behind a
        // NULL or perfectly fine parent-level price attribute.
        $collection->addAttributeToFilter('price', [
            ['null' => true],
            ['lteq' => Data::MAX_SYNC_PRICE],
        ]);

        if ($lastSyncAt !== null) {
            $collection->addAttributeToFilter('updated_at', ['gt' => $lastSyncAt]);
        }

        // OOS exclusion — full/cron sync only, never the real-time save observer.
        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            ['stock_id' => 1],
            'inner'
        );
        $collection->addFieldToFilter('is_in_stock', 1);

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);
        return $collection;
    }
}
