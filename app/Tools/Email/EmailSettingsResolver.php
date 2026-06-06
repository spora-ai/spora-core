<?php

declare(strict_types=1);

namespace Spora\Tools\Email;

use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Resolves IMAP/SMTP settings for the Email tool. Centralizes the "config
 * incomplete" check and the SMTP recipient allowlist so the public methods
 * in EmailTool can stay short.
 */
final class EmailSettingsResolver
{
    // IMAP settings keys
    private const KEY_IMAP_HOST       = 'core.imap.host';
    private const KEY_IMAP_PORT       = 'core.imap.port';
    private const KEY_IMAP_ENCRYPTION = 'core.imap.encryption';
    private const KEY_EMAIL_USERNAME  = 'core.email.username';
    private const KEY_EMAIL_PASSWORD  = 'core.email.password';
    private const KEY_IMAP_TIMEOUT    = 'core.imap.timeout';

    // SMTP settings keys
    private const KEY_SMTP_FROM              = 'core.smtp.from';
    private const KEY_SMTP_ALLOWED_RECIPIENTS = 'core.smtp.allowed_recipients';

    private const MSG_IMAP_INCOMPLETE = 'IMAP configuration is incomplete. Please configure IMAP settings.';

    public function __construct(private readonly ToolConfigService $configService) {}

    /**
     * @return array<string, mixed>
     */
    public function fetchSettings(string $toolClass, int $agentId, ?int $userId): array
    {
        return $this->configService->getEffectiveSettings($toolClass, $agentId, $userId);
    }

    /**
     * @return array{host: string, port: string, encryption: string, username: string, password: string, timeout: string}|ToolResult
     */
    public function resolveImapSettingsOrFail(string $toolClass, int $agentId, ?int $userId): array|ToolResult
    {
        $settings = $this->resolveImapSettings($this->fetchSettings($toolClass, $agentId, $userId));
        if ($settings === null) {
            return new ToolResult(false, self::MSG_IMAP_INCOMPLETE);
        }
        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{host: string, port: string, encryption: string, username: string, password: string, timeout: string}|null
     */
    public function resolveImapSettings(array $settings): ?array
    {
        $host = $settings[self::KEY_IMAP_HOST] ?? '';
        $user = $settings[self::KEY_EMAIL_USERNAME] ?? '';
        $pass = $settings[self::KEY_EMAIL_PASSWORD] ?? '';

        if ($host === '' || $user === '' || $pass === '') {
            return null;
        }

        return [
            'host'       => $host,
            'port'       => (string) ($settings[self::KEY_IMAP_PORT] ?? '993'),
            'encryption' => (string) ($settings[self::KEY_IMAP_ENCRYPTION] ?? 'ssl'),
            'username'   => $user,
            'password'   => $pass,
            'timeout'    => (string) ($settings[self::KEY_IMAP_TIMEOUT] ?? '60'),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function validateSmtpSettings(array $settings, string $to): ?ToolResult
    {
        $host = $settings[self::KEY_SMTP_FROM] ?? '';
        $from = $settings[self::KEY_SMTP_FROM] ?? '';
        $smtpHost = $settings['core.smtp.host'] ?? '';

        if (empty($smtpHost) || empty($from)) {
            return new ToolResult(false, 'SMTP configuration is incomplete. Please configure SMTP Host and From Address in settings.');
        }

        $allowed = $settings[self::KEY_SMTP_ALLOWED_RECIPIENTS] ?? '';
        if (empty($allowed) || trim($allowed) === '*') {
            return null;
        }

        $allowedList = array_map('trim', explode(',', $allowed));
        if (!in_array($to, $allowedList, true)) {
            return new ToolResult(false, "SECURITY REJECTION: The agent is only permitted to send emails to: {$allowed}. Cannot send to {$to}");
        }
        return null;
    }
}
