<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Full-width sponsor banner shown on the PDP in place of the "Frequently
 * Bought Together" row when a product has no FBT items (see the
 * $pdprevampHasFbt / $pdprevampSponsorBannerFullHtml branch in
 * product-detail-page.phtml). Separate block from
 * {@see CreateFbtSponsorBannerBlock} so admins can build this one
 * independently in Page Builder (Content > Blocks) without it ever being
 * overwritten by, or interfering with, the FBT-row banner.
 */
class CreateFbtSponsorBannerFullBlock implements DataPatchInterface
{
    public const BLOCK_IDENTIFIER = 'pdp_fbt_sponsor_banner_full';

    private BlockRepositoryInterface $blockRepository;
    private BlockInterfaceFactory $blockFactory;

    public function __construct(
        BlockRepositoryInterface $blockRepository,
        BlockInterfaceFactory $blockFactory
    ) {
        $this->blockRepository = $blockRepository;
        $this->blockFactory = $blockFactory;
    }

    public function apply(): self
    {
        try {
            $this->blockRepository->getById(self::BLOCK_IDENTIFIER);
            return $this;
        } catch (NoSuchEntityException $e) {
            // block does not exist yet - create it below
        }

        $content = <<<HTML
<a href="https://www.sellmark.com" target="_blank" rel="noopener" class="pdp-fbt-sponsor-banner" style="display:block;height:100%;">
    <img src="{{media url="wysiwyg/ads/sellmark-banner.png"}}" alt="Sellmark - Top Brands. Unbeatable Value. Adventure Ready." style="width:100%;height:100%;object-fit:cover;border-radius:0.75rem;" />
</a>
HTML;

        $block = $this->blockFactory->create();
        $block->setIdentifier(self::BLOCK_IDENTIFIER)
            ->setTitle('PDP - Sponsor Banner (no FBT / full-width)')
            ->setContent($content)
            ->setIsActive(1)
            ->setData('stores', [0]);
        $this->blockRepository->save($block);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            CreateFbtSponsorBannerBlock::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
