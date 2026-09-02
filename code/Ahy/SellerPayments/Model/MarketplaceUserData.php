<?php
namespace Ahy\SellerPayments\Model;

use Magento\Framework\Model\AbstractModel;

class MarketplaceUserData extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Ahy\SellerPayments\Model\ResourceModel\MarketplaceUserData::class);
    }
}
