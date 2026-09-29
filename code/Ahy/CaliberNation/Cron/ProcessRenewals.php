<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Cron;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\ResourceModel\Membership\CollectionFactory;
use Ahy\CaliberNation\Model\Service\ActivateMembership;
use Ahy\CaliberNation\Model\Service\ActivityLogger;
use Ahy\CaliberNation\Model\Service\ExpiredMemberLocator;
use Ahy\CaliberNation\Model\Service\MembershipEmailService;
use Ahy\CaliberNation\Logger\CronLogger;
use Ahy\CaliberNation\Model\Service\RenewalTaxCalculator;
use Ahy\CaliberNation\Model\Service\RenewMembership;
use Psr\Log\LoggerInterface;

/**
 * Daily membership lifecycle cron:
 *  1. Auto-renewal — charge the saved card for every membership whose renewal date
 *     has arrived (and retry Renewal Pending), extending on success or failing
 *     → Renewal Pending → Expired per the retry policy.
 *  2. Paid-through expiry — for CANCELLED memberships whose renewal_date has passed,
 *     revert the member group, mark Expired, and email them (config-gated).
 *  3. Non-renewing expiry — for ACTIVE memberships with auto-renew OFF whose
 *     renewal_date has passed (nothing else ever transitions these — step 1 only
 *     looks at auto_renew=1), revert the member group, mark Expired, and email them.
 *  4. Advance renewal reminder — email active auto-renewing members N days before
 *     their renewal date (US advance-notice). Once per cycle.
 *  5. Win-back — email lapsed (Expired) members a rejoin offer once they become
 *     win-back eligible. Once per lapse.
 */
class ProcessRenewals
{
    public function __construct(
        private readonly CollectionFactory $membershipCollectionFactory,
        private readonly RenewMembership $renewMembership,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly ActivateMembership $activateMembership,
        private readonly ActivityLogger $activityLogger,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly MembershipEmailService $emailService,
        private readonly ExpiredMemberLocator $expiredMemberLocator,
        private readonly RenewalTaxCalculator $taxCalculator,
        private readonly CronLogger $cronLog
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Every line of one run shares a short id, so concurrent or adjacent runs
        // stay separable in the log file.
        $this->runId = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $started     = microtime(true);
        $this->cronLog->info(str_repeat('=', 72));
        $this->cronLog->info(sprintf('[%s] CRON START  %s', $this->runId, $now));

        $this->processRenewals($now);
        $this->expireCancelledMemberships($now);
        $this->expireNonRenewingMemberships($now);
        $this->sendRenewalReminders($now);
        $this->sendWinbackEmails();

        $this->cronLog->info(sprintf(
            '[%s] CRON END    %s  (%.2fs, %d membership(s) affected)',
            $this->runId,
            date('Y-m-d H:i:s'),
            microtime(true) - $started,
            $this->affected
        ));
    }

    /** Short id shared by every log line of the current run. */
    private string $runId = '';

    /** Memberships changed or emailed during this run. */
    private int $affected = 0;

    /**
     * One audit line per membership the run actually touched. Records who was
     * affected and what changed — the counts alone cannot answer "why did this
     * member's status change?" weeks later.
     */
    private function audit(string $pass, MembershipInterface $membership, string $detail): void
    {
        $this->affected++;
        $this->cronLog->info(sprintf(
            '[%s] %-18s membership=%d customer=%d member_no=%s status=%s renewal=%s | %s',
            $this->runId,
            $pass,
            (int) $membership->getEntityId(),
            (int) $membership->getCustomerId(),
            $membership->getMemberNumber() ?: '-',
            $membership->getStatus(),
            $membership->getRenewalDate() ?: '-',
            $detail
        ));
    }

    /** A pass that examined rows but changed nothing still deserves a line. */
    private function auditPass(string $pass, int $examined, int $changed): void
    {
        $this->cronLog->info(sprintf(
            '[%s] %-18s examined=%d changed=%d',
            $this->runId,
            $pass,
            $examined,
            $changed
        ));
    }

    private function processRenewals(string $now): void
    {
        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('auto_renew', 1)
            ->addFieldToFilter('status', ['in' => [
                MembershipInterface::STATUS_ACTIVE,
                MembershipInterface::STATUS_RENEWAL_PENDING,
            ]])
            ->addFieldToFilter('renewal_date', ['lteq' => $now]);

        $examined = 0;
        $count    = 0;
        foreach ($collection as $membership) {
            $examined++;
            $before = $membership->getRenewalDate();
            try {
                $result = $this->renewMembership->renew($membership);
                $count++;
                $this->audit('renewal', $membership, sprintf(
                    '%s - %s (renewal was %s)',
                    ($result['success'] ?? false) ? 'CHARGED' : 'DECLINED',
                    $result['message'] ?? '',
                    $before ?: '-'
                ));
            } catch (\Exception $e) {
                $this->audit('renewal', $membership, 'ERROR - ' . $e->getMessage());
                $this->logger->error(
                    '[CaliberNation] Renewal cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        $this->auditPass('renewal', $examined, $count);
        if ($count > 0) {
            $this->logger->info("[CaliberNation] Renewal cron processed {$count} membership(s).");
        }
    }

    /**
     * Paid-through expiry for cancelled memberships whose term has ended.
     */
    private function expireCancelledMemberships(string $now): void
    {
        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('status', MembershipInterface::STATUS_CANCELLED)
            ->addFieldToFilter('renewal_date', ['lteq' => $now]);

        $examined = 0;
        $count    = 0;
        foreach ($collection as $membership) {
            $examined++;
            try {
                $customerId = (int) $membership->getCustomerId();
                $membership->setStatus(MembershipInterface::STATUS_EXPIRED);
                $this->membershipRepository->save($membership);
                $this->activateMembership->revertMemberGroup($customerId);
                $this->activityLogger->log(
                    $customerId,
                    ActivityLogger::ACTION_STATUS_CHANGE,
                    'cancelled membership reached paid-through date → expired, member group reverted'
                );
                $this->sendExpiryEmail($membership, $customerId);
                $count++;
                $this->audit('expire-cancelled', $membership,
                    'cancelled → EXPIRED, member group reverted, expiry email sent');
            } catch (\Exception $e) {
                $this->audit('expire-cancelled', $membership, 'ERROR - ' . $e->getMessage());
                $this->logger->error(
                    '[CaliberNation] Cancelled-expiry cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        $this->auditPass('expire-cancelled', $examined, $count);
        if ($count > 0) {
            $this->logger->info("[CaliberNation] Expired {$count} paid-through cancelled membership(s).");
        }
    }

    /**
     * Expiry for members who let auto-renew stay OFF and whose paid term has ended.
     * processRenewals() only ever looks at auto_renew=1, so without this step an
     * active, non-auto-renewing membership past its renewal_date would never
     * transition — the member would keep benefits/pricing indefinitely.
     */
    private function expireNonRenewingMemberships(string $now): void
    {
        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('status', MembershipInterface::STATUS_ACTIVE)
            ->addFieldToFilter('auto_renew', 0)
            ->addFieldToFilter('renewal_date', ['lteq' => $now]);

        $examined = 0;
        $count    = 0;
        foreach ($collection as $membership) {
            $examined++;
            try {
                $customerId = (int) $membership->getCustomerId();
                $membership->setStatus(MembershipInterface::STATUS_EXPIRED);
                $this->membershipRepository->save($membership);
                $this->activateMembership->revertMemberGroup($customerId);
                $this->activityLogger->log(
                    $customerId,
                    ActivityLogger::ACTION_STATUS_CHANGE,
                    'active non-auto-renewing membership reached renewal date → expired, member group reverted'
                );
                $this->sendExpiryEmail($membership, $customerId);
                $count++;
                $this->audit('expire-no-autorenew', $membership,
                    'active (auto-renew off) → EXPIRED, member group reverted, expiry email sent');
            } catch (\Exception $e) {
                $this->audit('expire-no-autorenew', $membership, 'ERROR - ' . $e->getMessage());
                $this->logger->error(
                    '[CaliberNation] Non-renewing-expiry cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        $this->auditPass('expire-no-autorenew', $examined, $count);
        if ($count > 0) {
            $this->logger->info("[CaliberNation] Expired {$count} non-auto-renewing membership(s).");
        }
    }

    /**
     * Advance renewal reminder — active auto-renewing members whose renewal date is
     * within the configured window and who haven't been reminded this cycle.
     */
    private function sendRenewalReminders(string $now): void
    {
        if (!$this->config->isRenewalReminderEnabled()) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime($now . ' +' . $this->config->getRenewalReminderDays() . ' days'));

        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('auto_renew', 1)
            ->addFieldToFilter('status', MembershipInterface::STATUS_ACTIVE)
            ->addFieldToFilter('renewal_date', ['gteq' => $now])
            ->addFieldToFilter('renewal_date', ['lteq' => $cutoff])
            ->addFieldToFilter('renewal_reminder_sent_at', ['null' => true]);

        $count = 0;
        foreach ($collection as $membership) {
            try {
                $customerId = (int) $membership->getCustomerId();
                // Quote the tax-inclusive figure: this is an advance notice of what
                // the card will actually be charged, and RenewMembership adds tax.
                $subtotal = $this->config->getMembershipPrice();
                $total    = round($subtotal + $this->taxCalculator->getTax($customerId, $subtotal), 2);
                $this->emailService->send('caliber_nation_email_renewal_reminder_template', $customerId, [
                    'renewal_date' => date('F j, Y', strtotime((string) $membership->getRenewalDate())),
                    'amount'       => '$' . number_format($total, 2),
                    'has_card'     => false,
                ]);
                $membership->setData('renewal_reminder_sent_at', $now);
                $this->membershipRepository->save($membership);
                $count++;
                $this->audit('renewal-reminder', $membership, sprintf(
                    'reminder emailed for %s, quoted %s (incl. tax)',
                    date('F j, Y', strtotime((string) $membership->getRenewalDate())),
                    '$' . number_format($total, 2)
                ));
            } catch (\Exception $e) {
                $this->logger->error(
                    '[CaliberNation] Renewal-reminder cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        $this->auditPass('renewal-reminder', $collection->getSize(), $count);
        if ($count > 0) {
            $this->logger->info("[CaliberNation] Sent {$count} renewal reminder(s).");
        }
    }

    /**
     * Win-back — expired members who are win-back eligible and haven't been emailed
     * for this lapse. Eligibility (feature enabled + days threshold) is delegated to
     * ExpiredMemberLocator.
     */
    private function sendWinbackEmails(): void
    {
        if (!$this->config->isWinbackEmailEnabled()) {
            return;
        }

        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('status', MembershipInterface::STATUS_EXPIRED)
            ->addFieldToFilter('winback_email_sent_at', ['null' => true]);

        $count = 0;
        foreach ($collection as $membership) {
            try {
                $customerId = (int) $membership->getCustomerId();
                if (!$this->expiredMemberLocator->isWinbackEligible($customerId)) {
                    continue;
                }
                $savings = (float) $membership->getLifetimeSavings();
                $this->emailService->send('caliber_nation_email_winback_template', $customerId, [
                    'winback_price' => '$' . number_format($this->config->getWinbackPrice(), 2),
                    'total_savings' => '$' . number_format($savings, 2),
                    'has_savings'   => $savings > 0,
                ]);
                $membership->setData('winback_email_sent_at', date('Y-m-d H:i:s'));
                $this->membershipRepository->save($membership);
                $count++;
                $this->audit('winback', $membership, sprintf(
                    'win-back emailed at %s (lifetime savings %s)',
                    '$' . number_format($this->config->getWinbackPrice(), 2),
                    '$' . number_format($savings, 2)
                ));
            } catch (\Exception $e) {
                $this->audit('winback', $membership, 'ERROR - ' . $e->getMessage());
                $this->logger->error(
                    '[CaliberNation] Win-back cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        $this->auditPass('winback', $collection->getSize(), $count);
        if ($count > 0) {
            $this->logger->info("[CaliberNation] Sent {$count} win-back email(s).");
        }
    }

    /** Expiry notification (config-gated). */
    private function sendExpiryEmail(MembershipInterface $membership, int $customerId): void
    {
        if (!$this->config->isExpiryEmailEnabled()) {
            return;
        }
        $savings = (float) $membership->getLifetimeSavings();
        $this->emailService->send('caliber_nation_email_expiry_template', $customerId, [
            'total_savings' => '$' . number_format($savings, 2),
            'has_savings'   => $savings > 0,
        ]);
    }
}
