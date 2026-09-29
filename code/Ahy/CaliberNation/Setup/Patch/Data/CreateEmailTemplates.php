<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Email\Model\ResourceModel\Template\CollectionFactory;
use Magento\Email\Model\TemplateFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\State;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates an editable admin copy of each Caliber Nation email template and points
 * the config at it, so all six appear under Marketing > Email Templates on every
 * environment without anyone loading defaults by hand.
 *
 * TRADE-OFF - IMPORTANT
 * A row in email_template is a FROZEN SNAPSHOT that overrides the module's .html
 * file. Once these rows exist, editing the files no longer changes what is sent:
 * the database row wins. Any future change we make to an email must therefore be
 * applied in admin (or shipped as a NEW template), never by editing the file and
 * expecting it to take effect.
 *
 * This is the deliberate cost of having the templates present and editable in the
 * grid from day one, which is what the business asked for.
 *
 * SAFETY
 * - Runs once (patch registry) and is additionally guarded per-template by code,
 *   so re-running can never duplicate rows or overwrite a customised template.
 * - Content is produced by Template::loadDefault(), the same call the admin
 *   "Load Template" button makes, so a created row is byte-identical to what an
 *   admin would have got doing it manually.
 * - Never overwrites an existing row: if the client has already customised a
 *   template, that row is left exactly as it is.
 */
class CreateEmailTemplates implements DataPatchInterface
{
    /** Module template id => [admin-visible name, config path to point at it]. */
    private const TEMPLATES = [
        'caliber_nation_email_welcome_template' => [
            'Caliber Nation - Welcome / Membership Confirmed',
            'caliber_nation/email/welcome_template',
        ],
        'caliber_nation_email_renewal_template' => [
            'Caliber Nation - Renewal Confirmation / Receipt',
            'caliber_nation/email/renewal_template',
        ],
        'caliber_nation_email_renewal_reminder_template' => [
            'Caliber Nation - Upcoming Renewal Reminder',
            'caliber_nation/email/renewal_reminder_template',
        ],
        'caliber_nation_email_cancellation_template' => [
            'Caliber Nation - Cancellation Confirmation',
            'caliber_nation/email/cancellation_template',
        ],
        'caliber_nation_email_expiry_template' => [
            'Caliber Nation - Membership Expired',
            'caliber_nation/email/expiry_template',
        ],
        'caliber_nation_email_winback_template' => [
            'Caliber Nation - Win-back Offer',
            'caliber_nation/email/winback_template',
        ],
    ];

    public function __construct(
        private readonly TemplateFactory $templateFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly WriterInterface $configWriter,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {}

    public function apply(): self
    {
        // loadDefault() resolves a frontend theme file, so the frontend area must
        // be emulated — under the setup/adminhtml area the lookup fails.
        try {
            $this->appState->emulateAreaCode(Area::AREA_FRONTEND, [$this, 'createTemplates']);
        } catch (\Exception $e) {
            // Never break setup:upgrade over a cosmetic admin convenience.
            $this->logger->error('[CaliberNation] Email template creation failed: ' . $e->getMessage());
        }
        return $this;
    }

    public function createTemplates(): void
    {
        foreach (self::TEMPLATES as $moduleId => [$label, $configPath]) {
            try {
                $existingId = $this->findExistingId($label);
                if ($existingId !== null) {
                    // Already present (re-run, or the client made it themselves).
                    // Leave the row untouched; just make sure config points at it.
                    $this->configWriter->save($configPath, (string) $existingId);
                    continue;
                }

                $template = $this->templateFactory->create();
                $template->loadDefault($moduleId);

                // loadDefault() leaves the MODEL ID set to the template string id
                // ("caliber_nation_email_welcome_template"). Saving in that state
                // makes Magento attempt an UPDATE of a row that does not exist,
                // which silently persists nothing. Clearing the id forces an
                // INSERT — without this the patch appears to succeed while
                // creating no templates at all.
                $template->setId(null);

                $template->setTemplateCode($label)
                    // Links the row back to the module template, so admin shows
                    // which default it came from.
                    ->setOrigTemplateCode($moduleId)
                    ->setAddedAt(date('Y-m-d H:i:s'))
                    ->save();

                $this->configWriter->save($configPath, (string) $template->getId());

                $this->logger->info(sprintf(
                    '[CaliberNation] Created email template "%s" (id %d) and set %s.',
                    $label,
                    $template->getId(),
                    $configPath
                ));
            } catch (\Exception $e) {
                $this->logger->error(
                    "[CaliberNation] Could not create email template {$moduleId}: " . $e->getMessage()
                );
            }
        }
    }

    /** Existing admin row id for this template code, or null. */
    private function findExistingId(string $label): ?int
    {
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('template_code', $label)
            ->setPageSize(1);
        $row = $collection->getFirstItem();

        return $row->getId() ? (int) $row->getId() : null;
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
