<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel\Checkout;

use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells the checkout success page whether the order that was JUST placed contained
 * the Caliber Nation membership product — so the "Thank you" page can congratulate
 * the shopper on becoming a member. Detection matches on the configured membership
 * SKU (same definition RestrictPaymentMethods / MembershipInCart use).
 */
class SuccessMembership implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * True when the just-placed order contained ONLY the membership product.
     *
     * Drives the success page's labelling: a membership-only purchase is not a
     * shipment, so it shows a Member Number instead of an order number, while a
     * mixed order still needs its order number for fulfilment and tracking.
     */
    public function isMembershipOnly(): bool
    {
        if (!$this->justBecameMember()) {
            return false;
        }
        try {
            $sku   = $this->config->getMembershipSku();
            $order = $this->checkoutSession->getLastRealOrder();
            if (!$order || !$order->getId()) {
                return false;
            }
            foreach ($order->getAllVisibleItems() as $item) {
                if ($item->getSku() !== $sku) {
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] isMembershipOnly check failed: ' . $e->getMessage());
            // Fall back to showing the order number: losing the order reference is
            // worse than showing one unnecessarily.
            return false;
        }
    }

    /**
     * The buyer's member number, e.g. "CN-483920", or null if unavailable.
     * Read from the membership record (activation assigns it before this page
     * renders), NOT from the order.
     */
    public function getMemberNumber(): ?string
    {
        try {
            $order = $this->checkoutSession->getLastRealOrder();
            $customerId = $order ? (int) $order->getCustomerId() : 0;
            if (!$customerId) {
                return null;
            }
            return $this->membershipRepository->getByCustomerId($customerId)->getMemberNumber();
        } catch (\Exception) {
            // No membership row (or guest order) — caller omits the field.
            return null;
        }
    }

    /** True when the just-placed order includes the membership product. */
    public function justBecameMember(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }
        try {
            $sku = $this->config->getMembershipSku();
            if (!$sku) {
                return false;
            }
            $order = $this->checkoutSession->getLastRealOrder();
            if (!$order || !$order->getId()) {
                return false;
            }
            foreach ($order->getAllItems() as $item) {
                if ($item->getSku() === $sku) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] SuccessMembership check failed: ' . $e->getMessage());
        }
        return false;
    }
}
