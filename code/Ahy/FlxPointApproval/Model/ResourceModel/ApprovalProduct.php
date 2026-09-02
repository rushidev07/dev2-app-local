<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ApprovalProduct extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('flxpoint_approval_product', 'entity_id');
    }
}
