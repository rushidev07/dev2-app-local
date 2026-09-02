<?php
declare(strict_types=1);

namespace Ahy\ThemeCustomization\CustomerData;

use Magento\Checkout\Block\QuoteShortcutButtons;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Framework\View\LayoutFactory;

/**
 * Re-renders the mini-cart's PayPal/Amazon-Pay shortcut buttons (Magento\Checkout\Block\QuoteShortcutButtons,
 * aliased "extra_actions" in cart-drawer.phtml) as live customer-data, since that block's visibility depends
 * on the current quote grand total. Without this, the button is baked into the page once at load time and
 * never re-evaluated after an ajax add-to-cart.
 */
class ExtraActions implements SectionSourceInterface
{
    public function __construct(
        private readonly LayoutFactory $layoutFactory
    ) {
    }

    public function getSectionData(): array
    {
        $block = $this->layoutFactory->create()->createBlock(QuoteShortcutButtons::class);

        return [
            'html' => $block->toHtml(),
        ];
    }
}
