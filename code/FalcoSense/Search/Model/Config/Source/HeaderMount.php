<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which header block FalcoSense's search attaches to.
 *
 * This is NOT an on/off switch. FalcoSense's search is the product — it always
 * mounts. The only question is WHERE, because theme families name the header
 * search block differently:
 *
 *   Hyva-family  exposes  "header-search"
 *   Luma-family  puts Magento_Search's block at "top.search"
 *
 * AUTO is the default and should stay correct for almost every store: the
 * observer walks the active theme's inheritance chain and picks the family. The
 * explicit values exist for the cases detection cannot know about — a theme that
 * inherits from Luma but has been rebuilt with Hyva's block names, or vice versa.
 *
 * Detection deliberately happens at layout_load_before, not at render time. An
 * earlier attempt checked for the Hyva block from inside the block itself and
 * could not work: the Luma mount renders from "header.container", early in
 * page-wrapper, before the theme's header tree has been generated, so nothing
 * was there to detect and both mounts rendered.
 */
class HeaderMount implements OptionSourceInterface
{
    /** Detect from the active theme's inheritance chain. */
    public const AUTO = 'auto';

    /** Force Hyva-family: override the theme's own "header-search" block. */
    public const HYVA = 'hyva';

    /** Force Luma-family: remove "top.search", mount into "header.container". */
    public const LUMA = 'luma';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::AUTO, 'label' => __('Automatic (detect from theme)')],
            ['value' => self::HYVA, 'label' => __('Hyva-family — force')],
            ['value' => self::LUMA, 'label' => __('Luma-family — force')],
        ];
    }
}
