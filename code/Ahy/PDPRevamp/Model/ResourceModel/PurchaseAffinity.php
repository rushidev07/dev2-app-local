<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * "Which products were actually bought in the same order as this one?",
 * from order history - backs "Customers Also Bought".
 *
 * Deliberately a byte-for-byte port of Amasty\Mostviewed\Model\
 * ResourceModel\Product\LoadBoughtTogether's query (same join shape, same
 * configurable-variant-to-parent resolution, same store scoping, same
 * ordering), minus the period and order-status filters that query
 * supports - this store's admin wants purchase history counted
 * regardless of order age or order status, which is that query's default
 * behavior anyway (Amasty ships with the order-status filter empty and a
 * 30-day period; here the period restriction is removed outright rather
 * than configured to "no limit", since a period of 0 has a different
 * code path in Amasty's own query than never applying the clause at all).
 *
 * No lift/ubiquity adjustment (contrast Model\ResourceModel\
 * ProductAffinity, which has both): matching Amasty's own logic here
 * means matching its raw-count ranking too, not the more conservative
 * statistic FBT uses. The one deliberate departure from Amasty is that
 * the "which orders count" id list and the "never show this as a
 * companion" id list are now two separate arguments instead of one -
 * Amasty's own query reuses the same list for both, which is exactly
 * why a configurable product could recommend itself: its exclusion list
 * only ever contained child variant ids, never the parent's own id, so
 * the parent's own order row (Magento always writes one per configurable
 * purchase, alongside the child row) slipped through as a "companion."
 * Confirmed against this store's live data before fixing it.
 */
class PurchaseAffinity
{
    private const QUERY_LIMIT = 1000;

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param int[] $anchorProductIds The current product's own id for a
     *   simple product; its child variant ids for a configurable; its
     *   associated ids for grouped/bundle - see
     *   CustomersAlsoBought::getAnchorProductIds(), which resolves this
     *   the same way Amasty\Mostviewed\Model\ProductProvider::
     *   getProductIdsByType() does. Used to find qualifying orders.
     * @param int[] $excludeProductIds Never returned as a companion - the
     *   caller should pass $anchorProductIds plus the current product's
     *   own top-level id (so a configurable can't recommend itself) plus
     *   any store-wide excluded SKUs (e.g. a free promotional item that
     *   would otherwise dominate every result - see
     *   CustomersAlsoBought::getExcludedProductIds()).
     * @return int[] product ids, highest co-purchase count first
     */
    public function getCoBoughtProductIds(
        array $anchorProductIds,
        array $excludeProductIds,
        int $storeId,
        int $limit
    ): array {
        $anchorProductIds = array_values(array_unique(array_filter(array_map('intval', $anchorProductIds))));
        if (!$anchorProductIds || $limit < 1) {
            return [];
        }
        $excludeProductIds = array_values(array_unique(array_filter(array_map('intval', $excludeProductIds))));

        $connection = $this->resourceConnection->getConnection();
        $itemTable = $this->resourceConnection->getTableName('sales_order_item');
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        // A child variant's purchase is attributed to its parent
        // configurable product, same as the anchor resolution above -
        // "bought together" means the configurable, not one specific
        // size/color combination.
        $productIdField = $connection->getIfNullSql('parent_item.product_id', 'order_item.product_id');

        $select = $connection->select()
            ->from(['order_item' => $itemTable], [
                'id'  => $productIdField,
                'cnt' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->join(['order' => $orderTable], 'order_item.order_id = order.entity_id', [])
            ->join(['main_item' => $itemTable], 'main_item.order_id = order.entity_id', [])
            ->joinLeft(['parent_item' => $itemTable], 'parent_item.item_id = order_item.parent_item_id', [])
            ->where('main_item.product_id IN (?)', $anchorProductIds)
            ->where('order.store_id IN (?)', [$storeId])
            ->group($productIdField)
            ->order('cnt DESC')
            ->limit(min($limit, self::QUERY_LIMIT));

        if ($excludeProductIds) {
            $select->where("{$productIdField} NOT IN (?)", $excludeProductIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }
}
