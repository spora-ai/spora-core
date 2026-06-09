<?php

declare(strict_types=1);

namespace Spora\Tools\Email;

use Spora\Services\ImapClientInterface;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Validation and "guard" helpers for {@see \Spora\Tools\EmailTool}, pulled out
 * so the tool's method count and per-method return counts stay under the
 * SonarQube `S1448` / `S1142` caps without changing its public API.
 *
 * All-static and stateless: each call passes the collaborators it needs as
 * arguments. Same shape as {@see \Spora\Agents\SchemaValidator}.
 */
final class EmailValidationHelpers
{
    /**
     * @param array<string, string> $values  label -> value; the label is included in the error.
     */
    public static function requireNonEmptyStrings(array $values, string $message): ?ToolResult
    {
        foreach ($values as $value) {
            if (trim($value) === '') {
                return ToolResult::fail($message);
            }
        }
        return null;
    }

    /**
     * Mirrors {@see \Spora\Tools\EmailTool::withImapSettings()} for the SMTP
     * path: resolves settings, runs the allowlist check, and only invokes the
     * callback on success.
     *
     * @param callable(array<string, mixed>): ToolResult $callback
     */
    public static function withValidSmtpSettings(
        EmailSettingsResolver $resolver,
        string $toolClass,
        int $agentId,
        ?int $userId,
        string $to,
        callable $callback,
    ): ToolResult {
        $settings  = $resolver->fetchSettings($toolClass, $agentId, $userId);
        $smtpCheck = $resolver->validateSmtpSettings($settings, $to);
        if ($smtpCheck instanceof ToolResult) {
            return $smtpCheck;
        }
        return $callback($settings);
    }

    /**
     * Precondition for `createFolder`: `$name` must not already exist.
     *
     * @return array{settings: array<string, mixed>, folders: list<string>}|ToolResult
     */
    public static function withNewFolderGuard(
        EmailSettingsResolver $resolver,
        ImapClientInterface $imap,
        EmailMessageFormatter $formatter,
        string $toolClass,
        int $agentId,
        ?int $userId,
        string $name,
    ): array|ToolResult {
        return self::checkFolderInvariant($resolver, $imap, $formatter, $toolClass, $agentId, $userId, $name, mustExist: false);
    }

    /**
     * Precondition for `deleteFolder`: `$name` must already exist.
     *
     * @return array{settings: array<string, mixed>, folders: list<string>}|ToolResult
     */
    public static function withExistingFolderGuard(
        EmailSettingsResolver $resolver,
        ImapClientInterface $imap,
        EmailMessageFormatter $formatter,
        string $toolClass,
        int $agentId,
        ?int $userId,
        string $name,
    ): array|ToolResult {
        return self::checkFolderInvariant($resolver, $imap, $formatter, $toolClass, $agentId, $userId, $name, mustExist: true);
    }

    /**
     * @return array{settings: array<string, mixed>, folders: list<string>}|ToolResult
     */
    private static function resolveImapFoldersOrFail(
        EmailSettingsResolver $resolver,
        ImapClientInterface $imap,
        EmailMessageFormatter $formatter,
        string $toolClass,
        int $agentId,
        ?int $userId,
    ): array|ToolResult {
        $imapSettings = $resolver->resolveImapSettingsOrFail($toolClass, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }
        try {
            $folders = $imap->fetchFolderNames($imapSettings);
        } catch (Throwable $e) {
            return $formatter->formatImapError('Failed to fetch folders', $e);
        }
        return ['settings' => $imapSettings, 'folders' => $folders];
    }

    /**
     * @param list<string> $existingFolders
     */
    private static function folderExistenceFailure(string $name, bool $mustExist, array $existingFolders): ?ToolResult
    {
        $exists = in_array($name, $existingFolders, true);
        if ($mustExist && !$exists) {
            return ToolResult::ok("Folder '{$name}' does not exist.");
        }
        if (!$mustExist && $exists) {
            return ToolResult::ok("Folder '{$name}' already exists.");
        }
        return null;
    }

    /**
     * @return array{settings: array<string, mixed>, folders: list<string>}|ToolResult
     */
    private static function checkFolderInvariant(
        EmailSettingsResolver $resolver,
        ImapClientInterface $imap,
        EmailMessageFormatter $formatter,
        string $toolClass,
        int $agentId,
        ?int $userId,
        string $name,
        bool $mustExist,
    ): array|ToolResult {
        $payload = self::resolveImapFoldersOrFail($resolver, $imap, $formatter, $toolClass, $agentId, $userId);
        if ($payload instanceof ToolResult) {
            return $payload;
        }
        $failure = self::folderExistenceFailure($name, $mustExist, $payload['folders']);
        if ($failure !== null) {
            return $failure;
        }
        return $payload;
    }
}
