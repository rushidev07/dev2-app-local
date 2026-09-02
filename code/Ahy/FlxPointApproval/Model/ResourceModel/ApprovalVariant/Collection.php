<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Model\ResourceModel\ApprovalVariant;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ahy\FlxPointApproval\Model\ApprovalVariant;
use Ahy\FlxPointApproval\Model\ResourceModel\ApprovalVariant as ApprovalVariantResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ApprovalVariant::class, ApprovalVariantResource::class);
    }
}
