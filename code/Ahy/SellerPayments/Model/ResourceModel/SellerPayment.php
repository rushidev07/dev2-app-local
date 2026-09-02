<?php
namespace Ahy\SellerPayments\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SellerPayment extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('ahy_seller_payment_info', 'id');
    }
}
