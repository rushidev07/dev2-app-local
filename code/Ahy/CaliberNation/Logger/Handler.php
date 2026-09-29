<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Logger;

use Monolog\Logger;

/**
 * Writes the Caliber Nation cron audit trail to its own file, so a run's effect
 * on memberships can be read without sifting system.log.
 */
class Handler extends \Magento\Framework\Logger\Handler\Base
{
    /** @var int */
    protected $loggerType = Logger::INFO;

    /** @var string */
    protected $fileName = '/var/log/caliber-nation-cron.log';
}
