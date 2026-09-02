<?php
namespace Ahy\SellerPayments\Model\ResourceModel\MarketplaceUserData;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \Ahy\SellerPayments\Model\MarketplaceUserData::class,
            \Ahy\SellerPayments\Model\ResourceModel\MarketplaceUserData::class
        );
    }
}
