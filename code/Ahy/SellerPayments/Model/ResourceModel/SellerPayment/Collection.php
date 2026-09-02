<?php
namespace Ahy\SellerPayments\Model\ResourceModel\SellerPayment;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \Ahy\SellerPayments\Model\SellerPayment::class,
            \Ahy\SellerPayments\Model\ResourceModel\SellerPayment::class
        );
    }
}
