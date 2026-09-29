<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Calibernation;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;
use Ahy\CaliberNation\Model\SellerParticipationFactory;
use Ahy\CaliberNation\Model\ResourceModel\SellerParticipation as SellerParticipationResource;
use Ahy\CaliberNation\Model\ResourceModel\SellerParticipation\CollectionFactory;

/**
 * Processes the seller's Caliber Nation participation form submission.
 * URL: marketplace/calibernation/save (POST)
 */
class Save extends Action
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly CustomerUrl $customerUrl,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly MarketplaceHelper $marketplaceHelper,
        private readonly SellerParticipationFactory $participationFactory,
        private readonly SellerParticipationResource $participationResource,
        private readonly CollectionFactory $collectionFactory
    ) {
        parent::__construct($context);
    }

    public function dispatch(RequestInterface $request)
    {
        if (!$this->customerSession->authenticate($this->customerUrl->getLoginUrl())) {
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        }
        return parent::dispatch($request);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $backUrl  = ['marketplace/calibernation/index', ['_secure' => $this->getRequest()->isSecure()]];

        if (!$this->getRequest()->isPost() || !$this->formKeyValidator->validate($this->getRequest())) {
            $this->messageManager->addErrorMessage(__('Invalid form submission.'));
            return $redirect->setPath(...$backUrl);
        }

        if (!$this->marketplaceHelper->isSeller()) {
            return $redirect->setPath('marketplace/account/becomeseller', ['_secure' => $this->getRequest()->isSecure()]);
        }

        $sellerId      = (int) $this->customerSession->getCustomerId();
        $isEnabled     = (int) (bool) $this->getRequest()->getParam('is_enabled', 0);
        $discountType  = $this->getRequest()->getParam('discount_type', 'percent');
        $discountValue = (float) $this->getRequest()->getParam('discount_value', 0);
        $isEarlyAccess = (int) (bool) $this->getRequest()->getParam('is_early_access', 0);

        if (!in_array($discountType, ['percent', 'fixed'], true)) {
            $discountType = 'percent';
        }
        if ($discountType === 'percent' && $discountValue > 100) {
            $this->messageManager->addErrorMessage(
                __('Percentage discount cannot exceed 100%. Please enter a value between 0 and 100.')
            );
            return $redirect->setPath(...$backUrl);
        }
        $discountValue = max(0.0, round($discountValue, 4));

        try {
            // Load existing record or create new one — seller_id is always the current customer.
            $collection = $this->collectionFactory->create()
                ->addFieldToFilter('seller_id', $sellerId)
                ->setPageSize(1);

            $participation = $collection->getFirstItem();
            if (!$participation->getId()) {
                $participation = $this->participationFactory->create();
            }

            $participation->setSellerId($sellerId);
            $participation->setIsEnabled($isEnabled);
            $participation->setDiscountType($discountType);
            $participation->setDiscountValue($discountValue);
            $participation->setIsEarlyAccess($isEarlyAccess);

            $this->participationResource->save($participation);

            $this->messageManager->addSuccessMessage(
                __('Your Caliber Nation participation settings have been saved.')
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Could not save your settings. Please try again.')
            );
        }

        return $redirect->setPath(...$backUrl);
    }
}
