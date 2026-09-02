<?php
namespace Ahy\MissingImage\Logger;

use Monolog\Logger;

class Handler extends \Magento\Framework\Logger\Handler\Base
{
    protected $loggerType = Logger::INFO;

    protected $fileName = '/var/log/ActiveSellerMissingImage/missing_image.log';
}
