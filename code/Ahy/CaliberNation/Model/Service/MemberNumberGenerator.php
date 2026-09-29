<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Math\Random;
use Psr\Log\LoggerInterface;

/**
 * Issues the public-facing member number, e.g. "CN-483920".
 *
 * WHY RANDOM AND NOT SEQUENTIAL
 * A number derived from the membership's auto-increment id (100000 + id) is
 * trivially reversible: any member could subtract the offset and learn both
 * their exact signup rank and roughly how many members the programme has. That
 * is information the business should not hand out, so numbers are drawn at
 * random from the whole 6-digit space instead — nothing about signup order or
 * member count can be inferred from one, even by someone reading this code.
 *
 * UNIQUENESS
 * The `member_number` column carries a UNIQUE index, and that index — not the
 * pre-check in this class — is the real guarantee. The check below merely
 * avoids most collisions cheaply; if two concurrent signups draw the same
 * number anyway, the second INSERT is rejected by the database and the caller
 * retries. Correctness therefore never depends on this class winning a race.
 *
 * FAILURE POLICY
 * generate() returns null rather than throwing if it cannot find a free number
 * within MAX_ATTEMPTS. A paid signup must never fail over a cosmetic
 * identifier: the membership is still created, the miss is logged, and the
 * backfill patch can assign a number later.
 */
class MemberNumberGenerator
{
    /** Rendered prefix. The stored value includes it, so it is display-ready. */
    public const PREFIX = 'CN-';

    /** 6-digit space: 100000-999999 (never starts with 0, so length is stable). */
    private const MIN = 100000;
    private const MAX = 999999;

    /**
     * Random draws before giving up. Collisions are rare until the space is
     * heavily used (at 100k members ~11% of draws collide, so 10 attempts fail
     * with probability ~1e-10); this bound only exists so a nearly-full space
     * can never hang a checkout.
     */
    private const MAX_ATTEMPTS = 10;

    private const TABLE = 'ahy_caliber_nation_membership';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Random $random,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * A member number not currently in use, or null if none could be found.
     */
    public function generate(): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::PREFIX . $this->random->getRandomNumber(self::MIN, self::MAX);
            if (!$this->exists($candidate)) {
                return $candidate;
            }
        }

        $this->logger->error(sprintf(
            '[CaliberNation] Could not allocate a member number after %d attempts; '
            . 'membership saved without one (backfill patch can assign it later).',
            self::MAX_ATTEMPTS
        ));
        return null;
    }

    /** True when the number is already taken. */
    public function exists(string $memberNumber): bool
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        return (bool) $conn->fetchOne(
            $conn->select()->from($table, ['cnt' => 'COUNT(*)'])
                ->where('member_number = ?', $memberNumber)
        );
    }
}
