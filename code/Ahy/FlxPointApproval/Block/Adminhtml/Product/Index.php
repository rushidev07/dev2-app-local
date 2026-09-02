<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Block\Adminhtml\Product;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Ahy\FlxPointApproval\Model\ResourceModel\ApprovalProduct\Collection;
use Ahy\FlxPointApproval\Model\ResourceModel\ApprovalProduct\CollectionFactory;

class Index extends Template
{
    private CollectionFactory $collectionFactory;

    private const PAGE_SIZE = 20;

    public function __construct(
        Context           $context,
        CollectionFactory $collectionFactory,
        array             $data = []
    ) {
        $this->collectionFactory = $collectionFactory;
        parent::__construct($context, $data);
    }

    public function getProducts(): Collection
    {
        $status  = $this->getRequest()->getParam('status', 'pending');
        $page    = max(1, (int) $this->getRequest()->getParam('p', 1));
        $search  = trim((string) $this->getRequest()->getParam('q', ''));

        $collection = $this->collectionFactory->create();

        if ($status !== 'all') {
            $collection->addFieldToFilter('status', $status);
        }

        if ($search !== '') {
            $collection->addFieldToFilter(
                ['name', 'sku', 'flxpoint_sku'],
                [
                    ['like' => '%' . $search . '%'],
                    ['like' => '%' . $search . '%'],
                    ['like' => '%' . $search . '%'],
                ]
            );
        }

        $collection->setOrder('created_at', 'DESC');
        $collection->setPageSize(self::PAGE_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }

    public function getStatusCounts(): array
    {
        $counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach (['pending', 'approved', 'rejected'] as $s) {
            $col = $this->collectionFactory->create();
            $col->addFieldToFilter('status', $s);
            $counts[$s]   = $col->getSize();
            $counts['all'] += $counts[$s];
        }
        return $counts;
    }

    public function getImageUrl(string $imagePath, string $fallbackUrl): string
    {
        if ($imagePath !== '') {
            return $this->_urlBuilder->getBaseUrl(['_type' => \Magento\Framework\UrlInterface::URL_TYPE_MEDIA])
                . ltrim($imagePath, '/');
        }
        return $fallbackUrl;
    }

    public function getViewUrl(int $entityId): string
    {
        return $this->getUrl('ahy_flxpoint_approval/product/view', ['id' => $entityId]);
    }

    public function getPagerUrl(array $params = []): string
    {
        $current = [
            'status' => $this->getRequest()->getParam('status', 'pending'),
            'q'      => $this->getRequest()->getParam('q', ''),
        ];
        return $this->getUrl('*/*/*', array_merge($current, $params));
    }

    public function getCurrentStatus(): string
    {
        return $this->getRequest()->getParam('status', 'pending');
    }

    public function getCurrentSearch(): string
    {
        return (string) $this->getRequest()->getParam('q', '');
    }
}
