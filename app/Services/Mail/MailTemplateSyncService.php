<?php

declare(strict_types=1);

namespace Spora\Services\Mail;

use Spora\Models\MailTemplate;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\MailTemplateServiceInterface;

/**
 * Reconcile mail-template rows against the YAML defaults shipped in
 * `email-templates/`. New templates are inserted; existing rows that drifted
 * from their YAML are reported but never overwritten unless {@see force} is
 * set.
 *
 * Used by both `bin/spora mail:templates:sync` (operator-facing CLI) and the
 * `db:seed` flow (so a freshly-installed app picks up any YAMLs that were
 * added after the initial migration ran).
 */
final class MailTemplateSyncService
{
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_CREATED   = 'created';
    public const STATUS_FORCED    = 'forced';
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_DRIFT     = 'drift';

    public function __construct(
        private readonly EmailTemplateLoader $loader,
        private readonly MailTemplateServiceInterface $mailTemplateService,
    ) {}

    /**
     * Reconcile every YAML default against the DB.
     *
     * New YAMLs are inserted unconditionally. Existing rows that match the
     * YAML are left alone. Existing rows that drifted are only overwritten
     * when `$force` is true — otherwise they are reported as drift and the
     * operator must invoke `bin/spora mail:templates:sync --force` (or fix
     * the row manually).
     *
     * @param bool $force overwrite drifted rows without prompting
     *
     * @return array<string, string> per-template status keyed by template name
     */
    public function reconcileDefaults(bool $force): array
    {
        $statuses = [];

        foreach ($this->loader->getAll() as $template) {
            $name = (string) $template['name'];
            $row  = MailTemplate::where('name', $name)->first();

            if ($row === null) {
                $this->mailTemplateService->createTemplate($template);
                $statuses[$name] = self::STATUS_CREATED;
                continue;
            }

            $diff = $this->diffDefaults($template, $row);
            if ($diff === []) {
                $statuses[$name] = self::STATUS_UNCHANGED;
                continue;
            }

            if (!$force) {
                $statuses[$name] = self::STATUS_DRIFT;
                continue;
            }

            $this->mailTemplateService->updateTemplate((int) $row->id, $diff);
            $statuses[$name] = self::STATUS_FORCED;
        }

        return $statuses;
    }

    /**
     * Compute the field-level diff between a YAML default and the stored row.
     * Used by the CLI's `--check` output as well as {@see reconcileDefaults()}.
     *
     * @param array{name: string, subject: string, body: string|null, body_html: string|null} $template
     *
     * @return array<string, string|null> map of field name → desired YAML value, only for fields that differ
     */
    public function diffDefaults(array $template, MailTemplate $row): array
    {
        $diff = [];
        foreach (['subject', 'body', 'body_html'] as $field) {
            $want = $template[$field] ?? null;
            $have = $row->{$field};
            if ($want !== $have) {
                $diff[$field] = $want;
            }
        }

        return $diff;
    }

    /**
     * Insert a new mail template from a YAML default.
     *
     * @param array{name: string, subject: string, body: string|null, body_html: string|null} $template
     */
    public function createMissing(array $template): void
    {
        $this->mailTemplateService->createTemplate($template);
    }

    /**
     * Overwrite specific fields on an existing row. The caller is responsible
     * for computing the diff (typically via {@see diffDefaults()}) and for
     * deciding whether overwriting is appropriate — this method does no
     * safety check of its own.
     *
     * @param array<string, string|null> $diff
     */
    public function updateTemplate(int $templateId, array $diff): void
    {
        $this->mailTemplateService->updateTemplate($templateId, $diff);
    }
}
