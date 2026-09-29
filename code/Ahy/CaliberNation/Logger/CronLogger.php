<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Logger;

/**
 * Dedicated channel for the daily membership cron. Handlers are attached in
 * di.xml so this writes to var/log/caliber-nation-cron.log only — it never
 * duplicates into system.log.
 */
class CronLogger extends \Monolog\Logger
{
}
