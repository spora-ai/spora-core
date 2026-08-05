<?php

declare(strict_types=1);

namespace Spora\Services;

use RuntimeException;
use Spora\Models\MailTemplate;
use Spora\Services\Mail\MailTemplateRenderer;

/**
 * Service for mail template management.
 * All DB access for MailTemplate domain goes through this service.
 */
final class MailTemplateService implements MailTemplateServiceInterface
{
    public const SYSTEM_TEMPLATES = [
        'email_verification',
        'email_change_verification',
        'password_reset',
        'welcome',
        'scheduled_run_completed',
    ];

    public function getAllTemplates(): array
    {
        $templates = MailTemplate::select(['id', 'name'])->get();

        return $templates->map(fn(MailTemplate $t) => [
            'id'   => (int) $t->id,
            'name' => $t->name,
        ])->toArray();
    }

    public function getTemplate(int $templateId): ?array
    {
        $template = MailTemplate::find($templateId);
        if ($template === null) {
            return null;
        }

        return ['mail_template' => $this->serializeTemplate($template)];
    }

    public function createTemplate(array $data): array
    {
        $template = MailTemplate::create([
            'name'      => (string) $data['name'],
            'subject'   => (string) $data['subject'],
            'body'      => $data['body'] ?? null,
            'body_html' => $data['body_html'] ?? null,
        ]);

        return ['mail_template' => $this->serializeTemplate($template)];
    }

    public function updateTemplate(int $templateId, array $data): ?array
    {
        $template = MailTemplate::find($templateId);
        if ($template === null) {
            return null;
        }

        if (isset($data['name'])) {
            $template->name = (string) $data['name'];
        }
        if (isset($data['subject'])) {
            $template->subject = (string) $data['subject'];
        }
        if (array_key_exists('body', $data)) {
            $template->body = $data['body'] !== null ? (string) $data['body'] : null;
        }
        if (array_key_exists('body_html', $data)) {
            $template->body_html = $data['body_html'] !== null ? (string) $data['body_html'] : null;
        }

        $template->save();

        return ['mail_template' => $this->serializeTemplate($template)];
    }

    public function deleteTemplate(int $templateId): bool
    {
        $template = MailTemplate::find($templateId);
        if ($template === null) {
            return false;
        }

        // System templates cannot be deleted
        if (in_array($template->name, self::SYSTEM_TEMPLATES, true)) {
            return false;
        }

        $template->delete();

        return true;
    }

    public function previewTemplate(string $name, array $variables): array
    {
        $template = MailTemplate::where('name', $name)->first();
        if ($template === null) {
            throw new RuntimeException("Mail template '{$name}' not found.");
        }

        $rendered = $this->renderer->render(
            $variables,
            $template->subject ?? '',
            $template->body,
            $template->body_html,
        );

        return [
            'name'      => $template->name,
            'subject'   => $rendered->subject,
            'body'      => $rendered->bodyHtml,
            'body_text' => $rendered->bodyText,
        ];
    }

    public function __construct(
        private readonly MailTemplateRenderer $renderer,
    ) {}

    private function serializeTemplate(MailTemplate $template): array
    {
        return [
            'id'        => (int) $template->id,
            'name'      => $template->name,
            'subject'   => $template->subject,
            'body'      => $template->body,
            'body_html' => $template->body_html,
        ];
    }
}
