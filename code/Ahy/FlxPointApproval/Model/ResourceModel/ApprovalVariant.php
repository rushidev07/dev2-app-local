<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ApprovalVariant extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('flxpoint_approval_variant', 'entity_id');
    }
}
