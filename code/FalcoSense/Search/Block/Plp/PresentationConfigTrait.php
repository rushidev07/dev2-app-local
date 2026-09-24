<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block\Plp;

/**
 * Server-side access to the presentation settings the client reads off
 * window.FalcoSense.config.
 *
 * The Alpine components get these through Block\Plp\Config. Templates that
 * build markup in PHP — the SSR product cards, the slider — need the same
 * values before any JavaScript runs, and this is how they get them without
 * each block growing its own copy of the accessors.
 *
 * Requires the using class to hold a FalcoSense\Search\Helper\Data as $helper,
 * which Block\Search, Block\Category and the slider blocks all already do.
 */
trait PresentationConfigTrait
{
    /**
     * "Sold By" name for a product whose API record carries no seller.
     *
     * Empty is valid and means the line is omitted — see how the card templates
     * guard on it. Previously a literal store name in eight templates.
     */
    public function getDefaultSeller(): string
    {
        return $this->helper->getPlpDefaultSeller();
    }

    /** Placeholder for a missing or broken product image. '' renders nothing. */
    public function getFallbackImage(): string
    {
        return $this->helper->getPlpFallbackImage();
    }
}
