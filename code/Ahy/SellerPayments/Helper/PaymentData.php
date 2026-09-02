<?php
namespace Ahy\SellerPayments\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class PaymentData extends AbstractHelper
{
    /**
     * Wrap array data into PaymentDataObject instances
     *
     * @param array $paymentSources
     * @return PaymentDataObject[]
     */
    public function wrapPayments(array $paymentSources)
    {
        $payments = [];
        foreach ($paymentSources as $payment) {
            $payments[] = new PaymentDataObject($payment);
        }
        return $payments;
    }
}

class PaymentDataObject
{
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }
    public function getId()
    {
        return $this->data['id'] ?? null;
    }

    public function getBankName()
    {
        return $this->data['Bank Name'] ?? '';
    }

    public function getAccountNumber()
    {
        return $this->data['Account Number'] ?? '';
    }

    public function getRoutingNumber()
    {
        return $this->data['Routing Number'] ?? '';
    }
}
