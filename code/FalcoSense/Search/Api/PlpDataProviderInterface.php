<?php
declare(strict_types=1);

namespace FalcoSense\Search\Api;

use FalcoSense\Search\Model\Plp\PlpQuery;
use FalcoSense\Search\Model\Plp\PlpResult;

/**
 * The Port the search results page's server-side render goes through to get
 * "what products, in what order, with what facets" for the canonical
 * (unfiltered, page 1, default sort) view.
 *
 * Implementations MUST NOT throw for an unreachable/slow/empty platform —
 * return PlpResult::unavailable() instead, so the caller-side code path is
 * always just "did I get something usable back."
 */
interface PlpDataProviderInterface
{
    public function fetch(PlpQuery $query): PlpResult;
}
