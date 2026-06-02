<?php

declare(strict_types=1);

namespace Spora\Services;

interface ImapClientInterface
{
    /**
     * Fetch emails from INBOX.
     *
     * @param array<string, string> $settings
     * @param bool $markAsRead  When true, set the Seen flag on returned messages.
     * @param bool $unreadOnly  When true, return only messages without the Seen flag.
     *                          When false, return the most recent N messages.
     * @return list<array{uid: string, subject: string, from: string, date: string, body: string}>
     */
    public function fetchInboxMessages(array $settings, int $limit, bool $markAsRead, bool $unreadOnly): array;

    /**
     * Fetch emails from a specific folder.
     *
     * @param array<string, string> $settings
     * @return list<array{uid: string, subject: string, from: string, date: string, body: string}>
     */
    public function fetchFolderMessages(array $settings, string $folder, int $limit): array;

    /**
     * Fetch all available folders.
     *
     * @param array<string, string> $settings
     * @return list<string>
     */
    public function fetchFolderNames(array $settings): array;

    /**
     * Save a draft message to the Drafts folder.
     *
     * @param array<string, string> $settings
     * @return bool true on success, false on failure.
     */
    public function saveDraft(array $settings, string $to, string $subject, string $body): bool;

    /**
     * Create a new email folder.
     *
     * @param array<string, string> $settings
     * @return bool True on success, false on failure.
     */
    public function createFolder(array $settings, string $name): bool;

    /**
     * Rename an existing email folder.
     *
     * @param array<string, string> $settings
     * @return bool True on success, false on failure.
     */
    public function renameFolder(array $settings, string $oldName, string $newName): bool;

    /**
     * Delete an email folder.
     *
     * @param array<string, string> $settings
     * @return bool True on success, false on failure.
     */
    public function deleteFolder(array $settings, string $name): bool;

    /**
     * Move an email between folders.
     *
     * @param array<string, string> $settings
     * @return string The new UID on success, empty string on failure.
     */
    public function moveEmail(array $settings, int $uid, string $fromFolder, string $toFolder): string;

    /**
     * Delete an email.
     *
     * @param array<string, string> $settings
     * @return bool True on success, false on failure.
     */
    public function deleteEmail(array $settings, int $uid, string $folder): bool;

    /**
     * Set or unset a flag on an email.
     *
     * @param array<string, string> $settings
     * @return bool True on success, false on failure.
     */
    public function setEmailFlag(array $settings, int $uid, string $folder, string $flag, bool $enable): bool;
}
