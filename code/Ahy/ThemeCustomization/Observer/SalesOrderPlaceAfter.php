<?php
namespace Ahy\ThemeCustomization\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\Rule\CustomerFactory;

class SalesOrderPlaceAfter implements ObserverInterface
{
    protected $couponFactory;
    protected $couponResource;
    protected $ruleCustomerFactory;

    public function __construct(
        CouponFactory $couponFactory,
        CouponResource $couponResource,
        CustomerFactory $ruleCustomerFactory
    ) {
        $this->couponFactory = $couponFactory;
        $this->couponResource = $couponResource;
        $this->ruleCustomerFactory = $ruleCustomerFactory;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $couponCode = $order->getCouponCode();

        if (!$couponCode) {
            return;
        }

       
        $coupon = $this->couponFactory->create();
        $this->couponResource->load($coupon, $couponCode, 'code');

        if ($coupon->getId()) {
            $coupon->setTimesUsed($coupon->getTimesUsed() + 1);
            $this->couponResource->save($coupon);
        } else {
            return; // No valid coupon found
        }

        
        $ruleId = $coupon->getRuleId();
        $customerId = $order->getCustomerId();

        if ($customerId && $ruleId) {
            $ruleCustomer = $this->ruleCustomerFactory->create();
            $ruleCustomer->loadByCustomerRule($customerId, $ruleId);

            $ruleCustomer->setCustomerId($customerId);
            $ruleCustomer->setRuleId($ruleId);
            $ruleCustomer->setTimesUsed($ruleCustomer->getTimesUsed() + 1);
            $ruleCustomer->save();
        }
    }
}
