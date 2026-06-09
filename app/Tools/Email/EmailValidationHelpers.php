<?php

declare(strict_types=1);

namespace Spora\Tools\Email;

use Spora\Services\ImapClientInterface;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Stateless validation and "guard" helpers shared by the Email tool. Pulled out
 * of {@see \Spora\Tools\EmailTool} to keep that class's method count and
 * per-method return counts under the SonarQube `S1448` / `S1142` caps without
 * changing the public API.
 *
 * All methods are static: the inputs are explicit parameters (no hidden
 * instance state), and the helpers compose into the public methods through
 * simple call-site glue. The shape mirrors {@see \Spora\Agents\SchemaValidator}
 * (final class, all-static, no constructor).
 */
final class EmailValidationHelpers
{
    /**
     * Validate that every provided trimmed string is non-empty. Returns a
     * failure `ToolResult` for the first empty value, or `null` when all are
     * populated.
     *
     * @param array<string, string> $values  Map of label -> value (label is used in the error message).
     * @param string                $message Error message to surface when any value is empty.
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
     * Run a callback against fully-validated SMTP settings for the given
     * recipient. Hides the fetch + `validateSmtpSettings` branches and
     * returns the resolver's error (incomplete config or recipient rejected
     * by the allowlist) verbatim, mirroring `withImapSettings`.
     *
     * @param EmailSettingsResolver                              $resolver
     * @param callable(array<string, mixed>): ToolResult         $callback
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
     * Resolve IMAP settings, fetch the current folder list, and verify that
     * `$name` matches the requested existence check. Used by `createFolder`
     * (must NOT exist) and `deleteFolder` (MUST exist). Hides the imap-or-fail
     * branch, the fetch exception, and the membership check behind one helper
     * so the public methods keep their return counts low.
     *
     * - Returns a `ToolResult` (failure) when settings are incomplete, the
     *   folder list cannot be fetched, or the existence check fails.
     * - Returns the resolved `array<string, mixed>` of IMAP settings plus
     *   the validated folder list when the check passes.
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

    /**
     * Resolve IMAP settings and fetch the current folder list. Returns the
     * payload `{settings, folders}` on success, or a `ToolResult` failure if
     * either step fails.
     *
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
     * Build the existence-check failure message for a folder operation, or
     * return `null` if the current state matches the requested invariant.
     *
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
}
