<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\MailTemplate;

/**
 * Service for mail template management.
 * All DB access for MailTemplate domain goes through this service.
 */
final class MailTemplateService implements MailTemplateServiceInterface
{
    private const SYSTEM_TEMPLATES = [
        'email_verification',
        'password_reset',
        'welcome',
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
            'body_text' => $data['body_text'] ?? null,
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
        if (array_key_exists('body_text', $data)) {
            $template->body_text = $data['body_text'] !== null ? (string) $data['body_text'] : null;
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

        $rendered = $template->render($variables);

        return [
            'name'       => $template->name,
            'subject'    => $rendered['subject'],
            'body_text'  => $rendered['body_text'],
            'body_html'  => $rendered['body_html'],
        ];
    }

    // ── Private helpers ─────────────────────────────────────────────────────────

    private function serializeTemplate(MailTemplate $template): array
    {
        return [
            'id'        => (int) $template->id,
            'name'      => $template->name,
            'subject'   => $template->subject,
            'body_text' => $template->body_text,
            'body_html' => $template->body_html,
        ];
    }
}
