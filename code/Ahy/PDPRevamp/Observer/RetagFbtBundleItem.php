<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;

/**
 * Re-tags a cart line with the FBT bundle it was just added as part of.
 *
 * Plugin\Checkout\Model\Cart\TagFbtBundleItem tags the *product* before
 * Magento adds it, which only reaches the quote item when Magento creates a
 * brand new line. When the same product is already in the cart, Magento
 * merges the add into the existing line and keeps that line's existing
 * quote_item_option rows - so the item stayed pinned to whatever bundle it
 * first joined, forever.
 *
 * That is not an edge case, it is the normal second test: a shopper (or QA)
 * who adds a 3-item bundle and later adds a 2-item bundle containing one of
 * the same products ends up with one line tagged bundle A size 3 and one
 * tagged bundle B size 2. Neither bundle can then find all its siblings, so
 * Plugin\SalesRule\Model\RulesApplier silently discounts nothing and the
 * shopper is charged full price for a bundle the PDP advertised as discounted.
 *
 * checkout_cart_product_add_after fires with the resulting quote item for both
 * paths - new line and merged line - so re-asserting the two options here is
 * what makes the merge case correct. Quote\Item::addOption() updates an option
 * that already exists under the same code rather than appending a duplicate,
 * so the newest bundle always wins.
 *
 * A no-op for every other add-to-cart in the store: the two request params
 * this reads are only ever sent by the FBT widget's own JS (see
 * frequently-bought-together.phtml's postAddToCart(), which sets them on each
 * of the bundle's per-item POSTs to checkout/cart/add).
 */
class RetagFbtBundleItem implements ObserverInterface
{
    private const PARAM_BUNDLE_ID = 'ahy_fbt_bundle_id';
    private const PARAM_BUNDLE_SIZE = 'ahy_fbt_bundle_size';

    private RequestInterface $request;

    public function __construct(RequestInterface $request)
    {
        $this->request = $request;
    }

    public function execute(Observer $observer): void
    {
        $bundleId = trim((string) $this->request->getParam(self::PARAM_BUNDLE_ID, ''));
        $bundleSize = (int) $this->request->getParam(self::PARAM_BUNDLE_SIZE, 0);

        if ($bundleId === '' || $bundleSize < 2) {
            return;
        }

        $item = $observer->getEvent()->getData('quote_item');
        if (!$item instanceof QuoteItem) {
            return;
        }

        // A configurable add reports the child here in some paths; the bundle
        // belongs to the line the shopper sees and the one
        // Quote::getAllVisibleItems() returns, which is the parent.
        $item = $item->getParentItem() ?: $item;

        $item->addOption([
            'product' => $item->getProduct(),
            'code' => self::PARAM_BUNDLE_ID,
            'value' => $bundleId,
        ]);
        $item->addOption([
            'product' => $item->getProduct(),
            'code' => self::PARAM_BUNDLE_SIZE,
            'value' => (string) $bundleSize,
        ]);
    }
}
