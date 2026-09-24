<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Model\Config\Source\HeaderMount;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\DesignInterface;

/**
 * Declares the header-search mount that matches this store's theme family.
 *
 * FalcoSense's search always mounts — this only decides WHERE. Hyva-family
 * themes expose "header-search"; Luma-family themes use Magento_Search's
 * "top.search". Declaring both and suppressing the wrong one at render time does
 * not work (see detectFamily() below), so exactly one handle is added here,
 * before the layout is merged.
 */
class AddLayoutHandles implements ObserverInterface
{
    private const HANDLE_HYVA = 'falcosense_header_hyva';
    private const HANDLE_LUMA = 'falcosense_header_luma';

    public function __construct(
        private readonly Data $helper,
        private readonly DesignInterface $design,
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isFrontendEnabled()) {
            return;
        }

        $layout = $observer->getEvent()->getLayout();
        if (!$layout) {
            return;
        }

        $configured = $this->helper->getHeaderMount();
        $family = $configured === HeaderMount::AUTO ? $this->detectFamily() : $configured;

        $layout->getUpdate()->addHandle(
            $family === HeaderMount::LUMA ? self::HANDLE_LUMA : self::HANDLE_HYVA
        );
    }

    /**
     * Walk the active theme's inheritance chain looking for a Hyva ancestor.
     *
     * Every Hyva storefront theme inherits, directly or through its own parents,
     * from a theme in the Hyva vendor namespace (Hyva/default, Hyva/reset). A
     * Luma-family theme never does. Checking the chain rather than just the
     * active theme is what makes this work for a child theme several levels deep
     * — Everest's Ahy/Ahy_Everest2 inherits Hyva/default, for instance.
     *
     * WHY NOT CHECK FOR THE BLOCK INSTEAD
     * -----------------------------------
     * Because at this point the layout has not been merged, so no blocks exist
     * yet; and by the time they do, it is too late to add a handle. An earlier
     * version tried to detect from inside the rendering block and failed for the
     * opposite reason — "header.container" renders before the theme's header
     * tree is generated, so the Hyva block was not there to find and BOTH mounts
     * rendered. The theme is the one thing that is knowable at the right moment.
     *
     * Defaults to Hyva on any error: that is what this module shipped with before
     * the setting existed, so a failure here changes nothing for existing stores.
     */
    private function detectFamily(): string
    {
        try {
            $theme = $this->design->getDesignTheme();

            /* Bounded rather than while(true): a malformed theme table with a
               circular parent reference would otherwise hang the request. */
            for ($depth = 0; $theme !== null && $depth < 10; $depth++) {
                if (stripos((string) $theme->getCode(), 'Hyva/') === 0) {
                    return HeaderMount::HYVA;
                }
                $theme = $theme->getParentTheme();
            }

            return HeaderMount::LUMA;
        } catch (\Throwable $e) {
            return HeaderMount::HYVA;
        }
    }
}
