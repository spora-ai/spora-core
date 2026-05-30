<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Wraps an IMAP client for email reading operations.
 * Abstraction over webklex/php-imap to enable isolated testing.
 *
 * Settings (host, port, credentials etc.) are passed in by the caller (EmailTool)
 * so the interface stays purely about IMAP operations and is easy to mock.
 */
interface ImapClientInterface
{
    /**
     * Connect and return a list of message summaries from the INBOX.
     *
     * @param array<string, string> $settings
     * @return list<array{uid: string, subject: string, from: string, date: string, body: string}>
     */
    public function fetchInboxMessages(array $settings, int $limit, bool $markAsRead): array;

    /**
     * Connect and return a list of message summaries from a named folder.
     *
     * @param array<string, string> $settings
     * @return list<array{uid: string, subject: string, from: string, date: string, body: string}>
     */
    public function fetchFolderMessages(array $settings, string $folder, int $limit): array;

    /**
     * Connect and return all folder names.
     *
     * @param array<string, string> $settings
     * @return list<string>
     */
    public function fetchFolderNames(array $settings): array;

    /**
     * Save a draft message to the Drafts folder.
     *
     * @param array<string, string> $settings
     * @return string 'saved' on success, '' on failure.
     */
    public function saveDraft(array $settings, string $to, string $subject, string $body): string;

    /**
     * Create a new email folder.
     *
     * @param array<string, string> $settings
     */
    public function createFolder(array $settings, string $name): bool;

    /**
     * Rename an existing email folder.
     *
     * @param array<string, string> $settings
     */
    public function renameFolder(array $settings, string $oldName, string $newName): bool;

    /**
     * Delete an email folder.
     *
     * @param array<string, string> $settings
     */
    public function deleteFolder(array $settings, string $name): bool;

    /**
     * Move an email to a different folder. Returns the new UID assigned by the destination folder, or '' on failure.
     *
     * @param array<string, string> $settings
     * @return string The new UID in the destination folder, or '' on failure.
     */
    public function moveEmail(array $settings, int $uid, string $fromFolder, string $toFolder): string;

    /**
     * Delete an email (sets \Deleted flag and expunges).
     *
     * @param array<string, string> $settings
     */
    public function deleteEmail(array $settings, int $uid, string $folder): bool;

    /**
     * Set or unset a flag on an email.
     *
     * @param array<string, string> $settings
     */
    public function setEmailFlag(array $settings, int $uid, string $folder, string $flag, bool $enable): bool;
}
