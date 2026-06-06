<?php

declare(strict_types=1);

namespace Spora\Tools\Email;

/**
 * Generates human-readable action descriptions for each Email tool operation.
 * Extracted from EmailTool to keep the tool class under S1448's method limit.
 */
final class EmailActionDescriber
{
    private const PLACEHOLDER_FOLDER = '[folder]';

    private const PLACEHOLDER_UID = '[uid]';

    public function describe(string $operation, array $arguments): string
    {
        return match ($operation) {
            'read_inbox'    => $this->describeReadInbox($arguments),
            'list_folders'  => 'List all email folders',
            'read_folder'   => 'Read emails from a specific folder',
            'create_draft'  => 'Save an email draft to the Drafts folder',
            'send_email'    => $this->describeSendEmail($arguments),
            'create_folder' => $this->describeCreateFolder($arguments),
            'rename_folder' => $this->describeRenameFolder($arguments),
            'delete_folder' => $this->describeDeleteFolder($arguments),
            'move_email'    => $this->describeMoveEmail($arguments),
            'delete_email'  => $this->describeDeleteEmail($arguments),
            'mark_email_read' => $this->describeMarkEmailRead($arguments),
            default         => 'Perform an email operation',
        };
    }

    private function describeSendEmail(array $arguments): string
    {
        $to  = $arguments['to'] ?? 'Unknown Recipient';
        $sub = $arguments['subject'] ?? 'No Subject';
        return "Sending email to {$to} with subject: '{$sub}'";
    }

    private function describeReadInbox(array $arguments): string
    {
        $unreadOnly = (bool) ($arguments['unread_only'] ?? false);
        return $unreadOnly
            ? 'Read unread emails from the inbox'
            : 'Read recent emails from the inbox';
    }

    private function describeCreateFolder(array $arguments): string
    {
        $name = $arguments['new_folder'] ?? '[folder name]';
        return "Create email folder '{$name}'";
    }

    private function describeRenameFolder(array $arguments): string
    {
        $from = $arguments['folder'] ?? self::PLACEHOLDER_FOLDER;
        $to   = $arguments['new_folder'] ?? '[new name]';
        return "Rename email folder '{$from}' to '{$to}'";
    }

    private function describeDeleteFolder(array $arguments): string
    {
        $name = $arguments['folder'] ?? self::PLACEHOLDER_FOLDER;
        return "Delete email folder '{$name}'";
    }

    private function describeMoveEmail(array $arguments): string
    {
        $uid  = $arguments['uid'] ?? self::PLACEHOLDER_UID;
        $from = $arguments['folder'] ?? self::PLACEHOLDER_FOLDER;
        $to   = $arguments['new_folder'] ?? self::PLACEHOLDER_FOLDER;
        return "Move email UID {$uid} from '{$from}' to '{$to}'";
    }

    private function describeDeleteEmail(array $arguments): string
    {
        $uid    = $arguments['uid'] ?? self::PLACEHOLDER_UID;
        $folder = $arguments['folder'] ?? self::PLACEHOLDER_FOLDER;
        return "Delete email UID {$uid} from '{$folder}'";
    }

    private function describeMarkEmailRead(array $arguments): string
    {
        $uid  = $arguments['uid'] ?? self::PLACEHOLDER_UID;
        $read = ($arguments['read'] ?? true) ? 'read' : 'unread';
        return "Mark email UID {$uid} as {$read}";
    }
}
