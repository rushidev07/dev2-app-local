<?php
namespace Ahy\SellerPayments\Model;

use Magento\Framework\Model\AbstractModel;

class SellerPayment extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Ahy\SellerPayments\Model\ResourceModel\SellerPayment::class);
    }

    public function saveSellerPayment( $sellerId, $bankName, $accountNumber, $routingNumber, $id = null)
    {
        if ($id) {
            $this->load($id);
            if ($this->getSellerId() != $sellerId) {
                return false;
            }
        }

        $this->setSellerId($sellerId);
        $this->setBankName($bankName);
        $this->setAccountNumber($accountNumber);
        $this->setRoutingNumber($routingNumber);

        try {
            $this->save();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }



    public function getBySellerId($sellerId)
    {
        $this->load($sellerId, 'seller_id');     
        if ($this->getId()) {
            return $this;
        }
        return null;
    }
}
