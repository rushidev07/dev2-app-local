<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block;

use Magento\Framework\View\Element\Template;

/**
 * The FalcoSense header search form.
 *
 * Mounted twice in view/frontend/layout/default.xml, once per theme family,
 * because there is no single block name that exists everywhere:
 *
 *   - Hyvä-family themes name their header search block "header-search", so we
 *     take it over with <referenceBlock name="header-search">.
 *   - Luma-family themes have no such block; Magento_Search declares
 *     "top.search" inside the "header-wrapper" container instead. There we
 *     remove the native block and add our own into "header.container", which
 *     is defined by Magento's core page layouts and therefore present on any
 *     theme built on them.
 *
 * A referenceBlock naming a block that does not exist is silently ignored by
 * Magento, so each mount is inert on the theme family it isn't for. But on a
 * theme where BOTH names somehow resolve, we would render two search forms —
 * hence the guard in _toHtml() below.
 *
 * Magento's layout merge always lets theme-level layout files win over module
 * layout files for the "template" attribute — confirmed directly via Magento's
 * own template hints and temporary debug logging in
 * Magento\Framework\View\Layout\Generator\Block::generateBlock() on 2026-08-20:
 * Hyva's default theme's own Magento_Theme/layout/default.xml resets
 * "header-search"'s template to its own stock file no matter what this
 * module's layout XML sets, regardless of module load order.
 *
 * That merge only ever touches the "template" attribute though — never
 * "class". So this class assignment survives untouched, and getTemplate()
 * below simply never looks at whatever "template" value layout XML ended up
 * with, sidestepping the problem entirely instead of trying to win it.
 */
class HeaderSearchForm extends Template
{
    /**
     * The block name used by the Hyvä-family mount. The Luma-family mount uses
     * a different name and defers to this one whenever it is present.
     */
    private const PRIMARY_BLOCK_NAME = 'header-search';

    public function getTemplate()
    {
        return "FalcoSense_Search::html/header/search-form.phtml";
    }

    /**
     * Render nothing if this is the Luma-family fallback mount and the
     * Hyvä-family one already exists on this page — otherwise a theme that
     * happens to satisfy both would show the search form twice.
     */
    protected function _toHtml()
    {
        $layout = $this->getLayout();

        /*
         * hasElement(), not getBlock().
         *
         * getBlock() only sees blocks Magento has already GENERATED. This mount
         * lives in "header.container", which renders early in page-wrapper —
         * before the theme's own header tree (and therefore before
         * "header-search") has been generated. So getBlock() returned null here,
         * the guard passed, and BOTH mounts rendered: the Hyva one correctly
         * inside the header, and this one as a bare full-width child of
         * page-wrapper, which showed as an empty full-bleed panel.
         *
         * hasElement() inspects the merged layout STRUCTURE instead, so it gives
         * the same answer no matter when this block happens to render.
         *
         * getBlock() is kept as a fallback for layout implementations that do not
         * expose hasElement().
         */
        $primaryDeclared = $layout
            && (
                (method_exists($layout, 'hasElement') && $layout->hasElement(self::PRIMARY_BLOCK_NAME))
                || $layout->getBlock(self::PRIMARY_BLOCK_NAME)
            );

        if ($primaryDeclared && $this->getNameInLayout() !== self::PRIMARY_BLOCK_NAME) {
            return '';
        }

        return parent::_toHtml();
    }
}
