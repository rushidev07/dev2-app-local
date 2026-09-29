<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Model\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Works out the tax due on an off-session membership renewal charge.
 *
 * WHY THIS EXISTS
 * A cron renewal charges the saved card directly through the Authorize.Net CIM
 * API — there is no quote and no order, so none of Magento's total collectors
 * (including tax) ever run. Left alone, a member taxed at signup would be
 * charged the bare config price on renewal: the wrong amount, and no tax
 * remitted. This service reproduces what checkout would have calculated.
 *
 * HOW
 * Magento's own TaxCalculationInterface does the work, fed a synthetic
 * QuoteDetails describing "one membership product, delivered to this address".
 * Rates therefore come from the same Tax Rules / Tax Zones and Rates the client
 * configures in admin — this class holds no rate knowledge of its own and needs
 * no changes when they edit their tax setup.
 *
 * ADDRESS
 * Tax is destination-based. A virtual membership has no shipping address, so the
 * customer's DEFAULT BILLING address is used — matching how Magento itself
 * resolves tax for virtual carts when tax/calculation/based_on = shipping.
 *
 * FAILURE POLICY: never block the renewal.
 * If tax cannot be determined (no address, unconfigured rates, an exception),
 * getTax() returns 0.0 and logs. Charging the base price is a recoverable
 * bookkeeping issue; a hard failure would expire a paying member's benefits.
 * A store using tax-inclusive catalog prices also returns 0.0 — the price
 * already contains the tax, so adding more would double-charge.
 */
class RenewalTaxCalculator
{
    /** Store already treats catalog prices as tax-inclusive → nothing to add. */
    private const XML_PATH_PRICE_INCLUDES_TAX = 'tax/calculation/price_includes_tax';

    public function __construct(
        private readonly Config $config,
        private readonly TaxCalculationInterface $taxCalculation,
        private readonly QuoteDetailsInterfaceFactory $quoteDetailsFactory,
        private readonly QuoteDetailsItemInterfaceFactory $quoteDetailsItemFactory,
        private readonly TaxClassKeyInterfaceFactory $taxClassKeyFactory,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionInterfaceFactory $regionFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Tax due on $amount for this customer's renewal, rounded to 2dp.
     * Returns 0.0 when tax cannot or should not be applied (never throws).
     */
    public function getTax(int $customerId, float $amount, ?int $storeId = null): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        // Tax-inclusive stores: the configured price already contains the tax.
        if ($this->scopeConfig->isSetFlag(
            self::XML_PATH_PRICE_INCLUDES_TAX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        )) {
            return 0.0;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            $this->logger->warning("[CaliberNation] Renewal tax: customer {$customerId} not found; charging untaxed.");
            return 0.0;
        }

        $address = $this->getDefaultBillingAddress($customer->getAddresses(), (int) $customer->getDefaultBilling());
        if (!$address) {
            $this->logger->warning(
                "[CaliberNation] Renewal tax: no billing address for customer {$customerId}; charging untaxed."
            );
            return 0.0;
        }

        try {
            $details = $this->buildQuoteDetails($address, $amount, (int) $customer->getGroupId(), $storeId);
            $result  = $this->taxCalculation->calculateTax($details, $storeId);
            // round(): rates like 8.375% yield fractions of a cent the gateway cannot charge.
            return round((float) $result->getTaxAmount(), 2);
        } catch (\Exception $e) {
            $this->logger->error(
                "[CaliberNation] Renewal tax calculation failed for customer {$customerId}: " . $e->getMessage()
            );
            return 0.0;
        }
    }

    /**
     * A synthetic single-line quote: the real membership product, at the renewal
     * amount, shipped nowhere. Passing the actual product's tax class (rather
     * than hardcoding one) means the client's choice in admin is respected.
     */
    private function buildQuoteDetails(
        AddressInterface $address,
        float $amount,
        int $customerGroupId,
        ?int $storeId
    ): \Magento\Tax\Api\Data\QuoteDetailsInterface {
        $item = $this->quoteDetailsItemFactory->create();
        $item->setCode('caliber_nation_renewal')
            ->setType('product')
            ->setQuantity(1)
            ->setUnitPrice($amount)
            // Renewal price is exclusive of tax; tax is added on top of it.
            ->setTaxClassKey($this->productTaxClassKey())
            ->setIsTaxIncluded(false);

        $details = $this->quoteDetailsFactory->create();
        $details->setBillingAddress($address)
            // Virtual product: destination for tax purposes IS the billing address.
            ->setShippingAddress($address)
            ->setCustomerTaxClassKey($this->customerTaxClassKey($customerGroupId))
            ->setCustomerId(null)
            ->setItems([$item]);

        return $details;
    }

    /** Tax class of the configured membership product, by id. */
    private function productTaxClassKey(): TaxClassKeyInterface
    {
        $taxClassId = null;
        $sku = $this->config->getMembershipSku();
        if ($sku) {
            try {
                $taxClassId = (int) $this->productRepository->get($sku)->getTaxClassId();
            } catch (\Exception) {
                $taxClassId = null;
            }
        }

        return $this->taxClassKeyFactory->create()
            ->setType(TaxClassKeyInterface::TYPE_ID)
            ->setValue((string) ($taxClassId ?: 0));
    }

    /**
     * Customer tax class for the customer's group.
     *
     * NOTE: a group id is NOT a tax class id — group 6 maps to tax class 3
     * ("Retail Customer"). Passing the group id here matches no tax rule and
     * silently yields zero tax, so it must be resolved through the group.
     */
    private function customerTaxClassKey(int $customerGroupId): TaxClassKeyInterface
    {
        $taxClassId = null;
        try {
            $taxClassId = (int) $this->groupRepository->getById($customerGroupId)->getTaxClassId();
        } catch (\Exception $e) {
            $this->logger->warning(
                "[CaliberNation] Renewal tax: could not resolve tax class for group {$customerGroupId}: "
                . $e->getMessage()
            );
        }

        return $this->taxClassKeyFactory->create()
            ->setType(TaxClassKeyInterface::TYPE_ID)
            ->setValue((string) ($taxClassId ?: 0));
    }

    /**
     * The customer's default billing address as a tax-usable address.
     * Falls back to the first address on file when no default is flagged —
     * better an approximate jurisdiction than no tax at all.
     */
    private function getDefaultBillingAddress(?array $addresses, int $defaultBillingId): ?AddressInterface
    {
        if (empty($addresses)) {
            return null;
        }

        $chosen = null;
        foreach ($addresses as $candidate) {
            if ($defaultBillingId && (int) $candidate->getId() === $defaultBillingId) {
                $chosen = $candidate;
                break;
            }
        }
        $chosen ??= $addresses[0];

        // Region must be passed as a region object for state-level rates to match.
        $address = $this->addressFactory->create();
        $address->setCountryId($chosen->getCountryId())
            ->setPostcode($chosen->getPostcode())
            ->setCity($chosen->getCity())
            ->setStreet($chosen->getStreet());

        if ($chosen->getRegion()) {
            $address->setRegion(
                $this->regionFactory->create()
                    ->setRegionId((int) $chosen->getRegion()->getRegionId())
                    ->setRegion($chosen->getRegion()->getRegion())
                    ->setRegionCode($chosen->getRegion()->getRegionCode())
            );
        }

        return $address->getCountryId() ? $address : null;
    }
}
