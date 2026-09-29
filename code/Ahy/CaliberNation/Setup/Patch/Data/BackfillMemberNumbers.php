<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Ahy\CaliberNation\Model\Service\MemberNumberGenerator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives every pre-existing membership a member number.
 *
 * Member numbers are assigned at activation, so memberships created before the
 * feature existed have none — their account card and success page would render a
 * blank identifier. This patch fills those in once.
 *
 * Idempotent in the strict sense: it only touches rows where member_number IS
 * NULL, so re-running (or running after new signups) never reissues a number
 * that a member has already been shown.
 *
 * Deliberately NOT ordered by entity_id semantics: numbers come from the same
 * random generator used at signup, so back-filled members are indistinguishable
 * from new ones and no signup ordering leaks through the back door.
 */
class BackfillMemberNumbers implements DataPatchInterface
{
    private const TABLE = 'ahy_caliber_nation_membership';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MemberNumberGenerator $generator,
        private readonly LoggerInterface $logger
    ) {}

    public function apply(): self
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $ids = $conn->fetchCol(
            $conn->select()->from($table, ['entity_id'])
                ->where('member_number IS NULL OR member_number = ?', '')
        );

        if (!$ids) {
            return $this;
        }

        $assigned = 0;
        foreach ($ids as $id) {
            $number = $this->generator->generate();
            if ($number === null) {
                // Generator already logged; skip this row rather than abort the
                // upgrade — a missing cosmetic id must not break setup:upgrade.
                continue;
            }
            try {
                $conn->update($table, ['member_number' => $number], ['entity_id = ?' => (int) $id]);
                $assigned++;
            } catch (\Exception $e) {
                // Almost certainly the unique index rejecting a racing duplicate.
                $this->logger->warning(
                    "[CaliberNation] Backfill could not set member number for membership {$id}: " . $e->getMessage()
                );
            }
        }

        $this->logger->info(sprintf(
            '[CaliberNation] Backfilled %d of %d membership(s) with member numbers.',
            $assigned,
            count($ids)
        ));

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
