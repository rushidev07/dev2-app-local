<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service\Plp;

/**
 * Thrown only inside the Plp service layer, and always caught before it
 * reaches a block or template — FalcoSensePlpProvider turns it into
 * PlpResult::unavailable(). It never surfaces to a shopper.
 */
class PlatformRequestException extends \RuntimeException
{
}
