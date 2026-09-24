<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Block\Category;

use FalcoSense\Search\Block\Category as FalcoCategory;

/**
 * NO CONSTRUCTOR ON PURPOSE.
 *
 * This class used to declare one that did nothing but forward its arguments to
 * parent::__construct(). That made it a hardcoded copy of the parent's signature,
 * and it broke the moment FalcoSense_Search added two dependencies:
 *
 *   before: __construct(Context, Helper, LayerResolver, SearchTokenService, array $data)
 *   after:  __construct(Context, Helper, LayerResolver, SearchTokenService,
 *                       PageContext, PlpDataProviderInterface, array $data)
 *
 * With five arguments forwarded into a seven-parameter signature, $data landed in
 * the PageContext slot and di:compile failed with
 * "Required type: \FalcoSense\Search\Model\Plp\PageContext. Actual type: array".
 *
 * Omitting the constructor lets PHP inherit the parent's and lets Magento's DI
 * resolve whatever it currently declares, so this class no longer has to be
 * updated in lockstep with FalcoSense_Search.
 */
class FalcoProductGrid extends FalcoCategory
{

    public function getAddToCartUrl(): string
    {
        return $this->getUrl('checkout/cart/add');
    }

    public function getYotpoEndpointUrl(): string
    {
        return $this->getUrl('ahy_plprevamp/yotpo/bottomline');
    }

    public function getPageSize(): int
    {
        return 24;
    }
}
