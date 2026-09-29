<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Ahy\CaliberNation\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Switches the membership product from tax class "None" (0) to "Taxable Goods" (2).
 *
 * The membership was originally created untaxed. Client decision: membership purchases
 * are taxable. CreateMembershipProduct now sets class 2 for fresh installs, but that
 * patch is guarded by an existence check and never re-runs — so environments where the
 * product already exists (dev1, staging, production) need this patch to pick up the
 * change.
 *
 * Only the tax CLASS is set here. The rates and rules stay the client's to manage under
 * Stores > Tax Rules and Stores > Tax Zones and Rates.
 */
class MakeMembershipProductTaxable implements DataPatchInterface
{
    private const TAX_CLASS_TAXABLE = 2;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Config $config,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {}

    public function apply(): self
    {
        // Saving a product touches indexers/url rewrites, which require an area code.
        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, [$this, 'setTaxClass']);

        return $this;
    }

    public function setTaxClass(): void
    {
        $sku = $this->config->getMembershipSku();
        if (!$sku) {
            return;
        }

        try {
            // storeId 0 explicitly: tax_class_id is Website-scoped, and saving from a
            // store-scoped context writes a store-view OVERRIDE instead of the default.
            // That leaves store 0 = 0 (untaxed), so any NEW store view would inherit
            // "None" and silently stop charging tax.
            $product = $this->productRepository->get($sku, false, 0, true);
        } catch (NoSuchEntityException) {
            // Product not created yet — CreateMembershipProduct will set class 2 itself.
            return;
        }

        $product->setStoreId(0);

        if ((int) $product->getTaxClassId() === self::TAX_CLASS_TAXABLE) {
            return; // already taxable; nothing to do (idempotent)
        }

        $previous = (int) $product->getTaxClassId();

        try {
            $product->setTaxClassId(self::TAX_CLASS_TAXABLE);
            $this->productRepository->save($product);
            $this->logger->info(sprintf(
                '[CaliberNation] Membership %s tax class %d -> %d (Taxable Goods).',
                $sku,
                $previous,
                self::TAX_CLASS_TAXABLE
            ));
        } catch (\Exception $e) {
            // Never fail setup:upgrade over this — log so it can be set in admin instead.
            $this->logger->error(
                '[CaliberNation] Could not set membership tax class: ' . $e->getMessage()
            );
        }
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [CreateMembershipProduct::class];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
