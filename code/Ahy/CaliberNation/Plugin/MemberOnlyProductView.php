<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Magento\Catalog\Controller\Product\View as ProductViewController;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Redirects non-members to the 404 page when they navigate directly to a
 * member-only product URL (caliber_member_only = Yes).
 *
 * The collection observer (FilterMemberOnlyProducts) handles PLP/search, but a
 * visitor who bookmarks or shares a direct product URL bypasses the collection
 * entirely. This plugin closes that gap at the controller level.
 *
 * Uses getAttributeRawValue() to avoid a full product load — we only need one
 * attribute value, not the entire product object with all its EAV data.
 */
class MemberOnlyProductView
{
    public function __construct(
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly CustomerSession $customerSession,
        private readonly ProductResource $productResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly RedirectFactory $redirectFactory
    ) {}

    public function aroundExecute(
        ProductViewController $subject,
        callable $proceed
    ): ResultInterface {
        if ($this->config->isEnabled()) {
            $productId = (int) $subject->getRequest()->getParam('id');

            if ($productId) {
                $storeId = (int) $this->storeManager->getStore()->getId();
                $value   = $this->productResource->getAttributeRawValue(
                    $productId,
                    'caliber_member_only',
                    $storeId
                );

                if ($value == 1) {
                    $customerId = (int) $this->customerSession->getCustomerId();
                    if (!$this->memberAccess->isActiveMember($customerId)) {
                        return $this->redirectFactory->create()->setPath('noroute');
                    }
                }
            }
        }

        return $proceed();
    }
}
