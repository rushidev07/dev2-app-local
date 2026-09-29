<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Block\Seller;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Customer\Model\Session as CustomerSession;
use Ahy\CaliberNation\Model\SellerParticipation;
use Ahy\CaliberNation\Model\ResourceModel\SellerParticipation\CollectionFactory;

class Participation extends Template
{
    private ?SellerParticipation $participation = null;

    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly CollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSellerId(): int
    {
        return (int) $this->customerSession->getCustomerId();
    }

    /**
     * Returns the seller's participation record, or an empty (unsaved) model if none exists.
     */
    public function getParticipation(): SellerParticipation
    {
        if ($this->participation === null) {
            $collection = $this->collectionFactory->create()
                ->addFieldToFilter('seller_id', $this->getSellerId())
                ->setPageSize(1);
            /** @var SellerParticipation $item */
            $this->participation = $collection->getFirstItem();
        }
        return $this->participation;
    }

    public function hasRecord(): bool
    {
        return (bool) $this->getParticipation()->getId();
    }

    public function isParticipating(): bool
    {
        return $this->hasRecord() && (bool) $this->getParticipation()->getIsEnabled();
    }

    public function getDiscountTypeOptions(): array
    {
        return [
            'percent' => __('Percentage off (%)'),
            'fixed'   => __('Fixed amount off ($)'),
        ];
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl(
            'marketplace/calibernation/save',
            ['_secure' => $this->getRequest()->isSecure()]
        );
    }

    public function getFormattedDiscount(): string
    {
        $participation = $this->getParticipation();
        $value = number_format((float) $participation->getDiscountValue(), 2);
        return $participation->getDiscountType() === 'percent'
            ? $value . '%'
            : '$' . $value;
    }
}
