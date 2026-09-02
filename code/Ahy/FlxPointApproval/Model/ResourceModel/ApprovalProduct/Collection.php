<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Model\ResourceModel\ApprovalProduct;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ahy\FlxPointApproval\Model\ApprovalProduct;
use Ahy\FlxPointApproval\Model\ResourceModel\ApprovalProduct as ApprovalProductResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ApprovalProduct::class, ApprovalProductResource::class);
    }
}
