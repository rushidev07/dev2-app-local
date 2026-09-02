<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Plp;

/**
 * The full, rendered-ready payload for one listing view — what the server
 * renders the grid from, what gets embedded in the page for Alpine to seed
 * its own state from (skipping a redundant fetch on first load), and what a
 * future caching layer would store. All consumers read the same object, so
 * they cannot drift from each other.
 *
 * Ported from Ahy_SmartSearchLuma's Model/Plp/PlpResult.php (namespace swap
 * only) — platform-agnostic, no FalcoSense-specific wire-format assumptions.
 */
final class PlpResult
{
    /** Fresh response straight from the platform. */
    public const SOURCE_PLATFORM = 'platform';

    /** No platform data at all — caller must fall back to native rendering. */
    public const SOURCE_UNAVAILABLE = 'unavailable';

    /**
     * @param PlpItem[]  $items
     * @param PlpFacet[] $facets
     */
    public function __construct(
        public readonly array $items,
        public readonly array $facets,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $source = self::SOURCE_PLATFORM,
        public readonly int $fetchedAt = 0,
        public readonly array $meta = [],
    ) {
    }

    public static function unavailable(): self
    {
        return new self([], [], 0, 1, 0, self::SOURCE_UNAVAILABLE, time());
    }

    public function isUnavailable(): bool
    {
        return $this->source === self::SOURCE_UNAVAILABLE;
    }

    /**
     * Usable = we have real products to show. An empty-but-successful platform
     * response (a genuinely empty search) is NOT usable for SSR — better to
     * let Alpine's existing zero-results UI render than publish an empty
     * crawlable grid.
     */
    public function isUsable(): bool
    {
        return !$this->isUnavailable() && $this->items !== [];
    }

    public function totalPages(): int
    {
        if ($this->perPage < 1) {
            return 1;
        }

        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function toArray(): array
    {
        return [
            'success'   => true,
            'data'      => array_map(static fn (PlpItem $i) => $i->toArray(), $this->items),
            'facets'    => array_map(static fn (PlpFacet $f) => $f->toArray(), $this->facets),
            'pagination' => [
                'total'    => $this->total,
                'per_page' => $this->perPage,
            ],
        ];
    }
}
