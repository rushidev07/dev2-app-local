<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * "Which of these candidate products did shoppers actually view alongside
 * this one?", from Magento's own view-tracking table.
 *
 * `report_viewed_product_index` is populated in real time by core Magento
 * (Magento\Reports\Observer\CatalogProductViewObserver, fired on every
 * product page view) - no cron, no extra tracking code, nothing to enable
 * beyond the Magento_Reports module already being on. Query shape mirrors
 * Amasty\Mostviewed\Model\ResourceModel\Product\LoadViews - same table,
 * same visitor/customer pairing - adapted to return per-candidate counts
 * instead of an unscoped top-1000 list, since this is used as a ranking
 * boost within an already category-scoped candidate pool rather than as
 * the pool's source.
 *
 * Deliberately a raw co-view count rather than ProductAffinity's lift
 * score: lift exists there to fight a store-wide ubiquitous item (a free
 * decal) winning on a store-wide candidate pool. Here the candidate pool
 * is already department-scoped, so that failure mode doesn't apply, and a
 * plain count is simpler to reason about for a secondary boost signal.
 */
class ViewAffinity
{
    private const QUERY_LIMIT = 1000;

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param int[] $candidateProductIds Only these products are counted -
     *   keeps the query cheap and scoped to whatever the caller already
     *   considers relevant, instead of scanning for a store-wide top list.
     * @return array<int, int> candidate product_id => co-view count
     */
    public function getCoViewedCounts(
        int $productId,
        array $candidateProductIds,
        int $storeId,
        int $lookbackDays
    ): array {
        $candidateProductIds = array_values(array_unique(array_filter(array_map('intval', $candidateProductIds))));
        if ($productId < 1 || !$candidateProductIds || $lookbackDays < 1) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('report_viewed_product_index');

        $visitors = $connection->fetchCol(
            $connection->select()
                ->from(['t' => $table], ['visitor_id'])
                ->where('t.product_id = ?', $productId)
                ->where('t.visitor_id IS NOT NULL')
                ->where('t.store_id = ?', $storeId)
                ->where('TO_DAYS(NOW()) - TO_DAYS(t.added_at) <= ?', $lookbackDays)
                ->limit(self::QUERY_LIMIT)
        );
        $visitors = array_unique($visitors);

        $customers = $connection->fetchCol(
            $connection->select()
                ->from(['t' => $table], ['customer_id'])
                ->where('t.product_id = ?', $productId)
                ->where('t.customer_id IS NOT NULL')
                ->where('t.store_id = ?', $storeId)
                ->where('TO_DAYS(NOW()) - TO_DAYS(t.added_at) <= ?', $lookbackDays)
                ->limit(self::QUERY_LIMIT)
        );
        // A logged-in view is usually logged under both columns - count it
        // once, the same dedup Amasty's own LoadViews.php applies.
        $customers = array_diff(array_unique($customers), $visitors);

        $counts = [];

        if ($visitors) {
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from(['t' => $table], ['product_id', 'cnt' => new \Zend_Db_Expr('COUNT(*)')])
                    ->where('t.visitor_id IN (?)', $visitors)
                    ->where('t.product_id IN (?)', $candidateProductIds)
                    ->group('t.product_id')
            );
            foreach ($rows as $pid => $cnt) {
                $counts[(int) $pid] = ($counts[(int) $pid] ?? 0) + (int) $cnt;
            }
        }

        if ($customers) {
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from(['t' => $table], ['product_id', 'cnt' => new \Zend_Db_Expr('COUNT(*)')])
                    ->where('t.customer_id IN (?)', $customers)
                    ->where('t.product_id IN (?)', $candidateProductIds)
                    ->group('t.product_id')
            );
            foreach ($rows as $pid => $cnt) {
                $counts[(int) $pid] = ($counts[(int) $pid] ?? 0) + (int) $cnt;
            }
        }

        return $counts;
    }
}
