<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block\Plp;

use FalcoSense\Search\Helper\Data as Helper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Supplies the per-store values that the PLP Alpine components read off
 * window.FalcoSense.config.
 *
 * These lived as literals in plp-config.phtml — Everest's seller names and
 * placeholder image — with a comment saying a non-Everest storefront had to
 * override the template. They are admin settings now, so the same module file
 * serves every store.
 */
class Config extends Template
{
    private Helper $helper;

    public function __construct(
        Context $context,
        Helper $helper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->helper = $helper;
    }

    /**
     * Keys match what web/js/plp/runtime.js and the components expect; renaming
     * one here without renaming it there silently disables that behaviour.
     *
     * @return array<string, mixed>
     */
    public function getPlpConfig(): array
    {
        return [
            'freeShippingSellers' => $this->helper->getPlpFreeShippingSellers(),
            'defaultSeller'       => $this->helper->getPlpDefaultSeller(),
            'fallbackImage'       => $this->helper->getPlpFallbackImage(),
            'cdnBase'             => $this->helper->getPlpCdnBase(),
            'scrollOffset'        => $this->helper->getPlpScrollOffset(),
        ];
    }

    /**
     * JSON safe to drop inside a <script> block: the HEX flags stop a seller
     * name containing "</script>" or a quote from breaking out of it.
     */
    public function getPlpConfigJson(): string
    {
        return (string) json_encode(
            $this->getPlpConfig(),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
