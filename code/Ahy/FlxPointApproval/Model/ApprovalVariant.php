<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Model;

use Magento\Framework\Model\AbstractModel;

class ApprovalVariant extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\ApprovalVariant::class);
    }
}
