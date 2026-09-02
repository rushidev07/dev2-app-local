<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Plp;

/**
 * One filter group as the platform reports it for the current listing —
 * matches the `facets` array shape in a live /api/v1/products response
 * (key, label, options[{value,count}], and optional min/max for the price
 * facet) so the embedded SSR payload round-trips into Alpine's buildFilters()
 * with no new mapping logic.
 */
final class PlpFacet
{
    /**
     * @param array<int, array{value: string, count: int}> $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $options,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
    ) {
    }

    public function toArray(): array
    {
        $out = [
            'key'     => $this->key,
            'label'   => $this->label,
            'options' => $this->options,
        ];
        if ($this->min !== null) {
            $out['min'] = $this->min;
        }
        if ($this->max !== null) {
            $out['max'] = $this->max;
        }

        return $out;
    }
}
