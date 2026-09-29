<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Removes member-only products from any product collection for guests and non-members.
 *
 * Runs on catalog_product_collection_load_before, which fires for category pages,
 * search results, layered navigation, and widgets — covering every PLP-style surface
 * without needing individual plugins per page type.
 *
 * Active Caliber Nation members (active status, or cancelled but within paid-through
 * window) see all products. Everyone else has caliber_member_only = 1 products excluded.
 */
class FilterMemberOnlyProducts implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly CustomerSession $customerSession
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if ($this->memberAccess->isActiveMember($customerId)) {
            return;
        }

        /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection $collection */
        $collection = $observer->getEvent()->getCollection();
        if (!$collection) {
            return;
        }

        // Include products where caliber_member_only = 0 OR the attribute is not set (NULL).
        // Passing the attribute code as a string (not an array) ensures a single EAV join
        // with OR conditions on the same column — more reliable than the array-of-arrays
        // form which creates two separate joins for the same attribute.
        $collection->addAttributeToFilter('caliber_member_only', [
            ['eq' => 0],
            ['null' => true],
        ]);
    }
}
