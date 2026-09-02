<?php
declare(strict_types=1);

namespace Ahy\Authorizenet\Magewire\Checkout\Payment;

use Magento\Framework\Exception\LocalizedException;
use Hyva\Checkout\Magewire\Checkout\Payment\MethodList as originCheckoutMethodList;
use Magento\Payment\Model\MethodList as PaymentMethodList;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Quote\Api\CartRepositoryInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;

class MethodList extends originCheckoutMethodList
{
    private PaymentMethodList $paymentMethodList;

    public function __construct(
        SessionCheckout         $sessionCheckout,
        CartRepositoryInterface $cartRepository,
        EvaluationResultFactory $evaluationResultFactory,
        PaymentMethodList       $paymentMethodList
    ) {
        parent::__construct($sessionCheckout, $cartRepository, $evaluationResultFactory);
        $this->paymentMethodList = $paymentMethodList;
    }

    public function mount(): void
    {
        try {
            $quote  = $this->sessionCheckout->getQuote();
            $method = $quote->getPayment()->getMethod();

            // Build the set of currently available (enabled + applicable) method codes.
            $available = array_map(
                fn($m) => $m->getCode(),
                $this->paymentMethodList->getAvailableMethods($quote)
            );

            // If the stored method is no longer available (disabled, removed, or
            // was set to authnetahypayment when authnet is now off), clear it so
            // the customer sees a clean method selector rather than triggering
            // the authnet PlaceOrderService with no card data.
            if ($method && !in_array($method, $available, true)) {
                $quote->getPayment()->setMethod(null);
                $this->cartRepository->save($quote);
                $method = null;
            }
        } catch (LocalizedException $exception) {
            $method = null;
        }

        $this->method = $method;
    }
}
