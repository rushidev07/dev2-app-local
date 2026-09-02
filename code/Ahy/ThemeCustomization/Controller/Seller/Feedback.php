<?php
namespace Ahy\ThemeCustomization\Controller\Seller;

use Webkul\Marketplace\Controller\Seller\Feedback as WebkulFeedback;

class Feedback extends WebkulFeedback
{
    public function execute()
    {
        $shopUrl = $this->helper->getCollectionUrl();

        if (!$shopUrl) {
            $shopUrl = $this->getRequest()->getParam('shop');
        }

        if ($shopUrl) {
            $data = $this->helper->getSellerDataByShopUrl($shopUrl);
            if ($data->getSize()) {
                $targetUrl  = $this->_url->getUrl('', ['_direct' => $shopUrl]);
                $currentUrl = $this->_url->getCurrentUrl();

                if ($currentUrl !== $targetUrl) {
                    return $this->resultRedirectFactory->create()
                        ->setUrl($targetUrl)
                        ->setHttpResponseCode(301);
                }
            }
        }

        return parent::execute();
    }
}
