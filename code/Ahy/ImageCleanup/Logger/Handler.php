<?php
/**
 * Copyright © Ahy Consulting All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Ahy\ImageCleanup\Logger;

use Monolog\Logger;

class Handler extends \Magento\Framework\Logger\Handler\Base
{
    protected $loggerType = Logger::INFO;

    protected $fileName = '/var/log/ImageCleanup/ImageCleanup.log';
}
