<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ImapClientInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Email\EmailActionDescriber;
use Spora\Tools\Email\EmailMessageFormatter;
use Spora\Tools\Email\EmailSettingsResolver;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Email tool supporting IMAP (read inbox, list folders, read folders) and SMTP (send, drafts).
 * Security: SMTP recipients are validated against core.smtp.allowed_recipients (comma-separated list or *).
 */
#[Tool(
    name: 'email',
    description: 'All email operations including reading inbox, listing folders, and sending emails. The "action" argument selects the operation.',
    displayName: 'Email',
    category: 'communication',
)]
#[ToolOperation(name: 'read_inbox', description: 'Read recent emails from the INBOX. Set unread_only=true to fetch only unread emails.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'list_folders', description: 'List all available email folders', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'read_folder', description: 'Read emails from a specific folder by name', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'create_draft', description: 'Save an email draft to the Drafts folder for later editing or sending', enabledByDefault: false, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'send_email', description: 'Send an email to a recipient', enabledByDefault: false, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'create_folder', description: 'Create a new email folder', enabledByDefault: false, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'rename_folder', description: 'Rename an existing email folder', enabledByDefault: false, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete_folder', description: 'Delete an email folder', enabledByDefault: false, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'move_email', description: 'Move an email to a different folder', enabledByDefault: false, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete_email', description: 'Permanently delete an email', enabledByDefault: false, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'mark_email_read', description: 'Mark an email as read or unread', enabledByDefault: false, requiresApprovalByDefault: true)]
// IMAP settings (for read operations)
#[ToolSetting(key: 'core.imap.host', label: 'IMAP Host', type: 'text', description: 'e.g. imap.example.com', )]
#[ToolSetting(key: 'core.imap.port', label: 'IMAP Port', type: 'text', description: 'Usually 993', default: '993')]
#[ToolSetting(key: 'core.imap.encryption', label: 'IMAP Encryption', type: 'select', description: 'Encryption method for IMAP', default: 'ssl', options: ['ssl' => 'SSL/Implicit TLS', 'tls' => 'TLS/STARTTLS', 'notls' => 'None (not recommended)'])]
#[ToolSetting(key: 'core.email.username', label: 'Email Username', type: 'text', description: 'Email address used for both IMAP and SMTP authentication', required: true)]
#[ToolSetting(key: 'core.email.password', label: 'Email Password', type: 'password', description: 'Email password or App password used for both IMAP and SMTP', required: true)]
#[ToolSetting(key: 'core.imap.timeout', label: 'IMAP Timeout', type: 'text', description: 'Seconds before an IMAP connection fails (default: 60)', default: '60')]
// SMTP settings (for send operations)
#[ToolSetting(key: 'core.smtp.host', label: 'SMTP Host', type: 'text', description: 'e.g. smtp.example.com', )]
#[ToolSetting(key: 'core.smtp.port', label: 'SMTP Port', type: 'text', description: 'Usually 587 or 465', default: '587')]
#[ToolSetting(key: 'core.smtp.encryption', label: 'SMTP Encryption', type: 'select', description: 'Encryption method for SMTP', default: 'tls', options: ['ssl' => 'SSL/Implicit TLS', 'tls' => 'TLS/STARTTLS', 'notls' => 'None (not recommended)'])]
#[ToolSetting(key: 'core.smtp.from', label: 'From Address', type: 'text', description: 'e.g. agent@spora.local', required: true, exposeToLlm: true)]
#[ToolSetting(key: 'core.smtp.allowed_recipients', label: 'Allowed Recipients', type: 'text', description: 'Comma-separated list of exact email addresses the agent is allowed to send to (or * for all).', exposeToLlm: true)]
#[ToolSetting(key: 'core.smtp.timeout', label: 'SMTP Timeout', type: 'text', description: 'Seconds before an SMTP connection fails (default: 30)', default: '30')]
// Tool parameters — `action` is auto-synthesized from the #[ToolOperation] list above.
// Declaration order here mirrors the hand-rolled schema's property order so the
// approval UI renders fields in the same place.
#[ToolParameter(name: 'limit', type: 'integer', description: 'Maximum number of emails to retrieve (default 5, max 20). Used with read_inbox.', required: false)]
#[ToolParameter(name: 'mark_as_read', type: 'boolean', description: 'If true, marks fetched emails as read. Irreversible. Defaults to false.', required: false)]
#[ToolParameter(name: 'unread_only', type: 'boolean', description: 'If true, returns only unread emails. Defaults to false (returns recent emails regardless of read state). Used with read_inbox.', required: false)]
#[ToolParameter(name: 'folder', type: 'string', description: 'The folder name to read from, rename from, delete, move from, or act on. E.g. INBOX, Sent, Drafts.', required: false)]
#[ToolParameter(name: 'to', type: 'string', description: 'The email address of the recipient.', required: false)]
#[ToolParameter(name: 'subject', type: 'string', description: 'The subject line of the email.', required: false)]
#[ToolParameter(name: 'body', type: 'string', description: 'The plain text body content of the email.', required: false)]
#[ToolParameter(name: 'new_folder', type: 'string', description: 'The new folder name for create_folder, rename_folder, or the destination folder for move_email.', required: false)]
#[ToolParameter(name: 'uid', type: 'integer', description: 'The UID of the email to move, delete, or mark read/unread.', required: false)]
#[ToolParameter(name: 'read', type: 'boolean', description: 'If true, marks as read. If false, marks as unread. Defaults to true. Used with mark_email_read.', required: false)]
final class EmailTool extends AbstractTool
{
    // SMTP settings keys (used in dispatchSmtpEmail)
    private const KEY_SMTP_HOST              = 'core.smtp.host';
    private const KEY_SMTP_PORT              = 'core.smtp.port';
    private const KEY_SMTP_ENCRYPTION        = 'core.smtp.encryption';
    private const KEY_EMAIL_USERNAME         = 'core.email.username';
    private const KEY_EMAIL_PASSWORD         = 'core.email.password';
    private const KEY_SMTP_FROM              = 'core.smtp.from';
    private const KEY_SMTP_TIMEOUT           = 'core.smtp.timeout';

    /** Default and maximum number of emails to read in one call. */
    private const DEFAULT_EMAIL_LIMIT = 5;
    private const MAX_EMAIL_LIMIT     = 20;

    private readonly EmailSettingsResolver $settingsResolver;
    private readonly EmailMessageFormatter $messageFormatter;

    public function __construct(
        ToolConfigService $configService,
        private readonly ImapClientInterface $imapClient,
        private readonly ?LoggerInterface $logger = null,
        private readonly EmailActionDescriber $actionDescriber = new EmailActionDescriber(),
    ) {
        $this->settingsResolver = new EmailSettingsResolver($configService);
        $this->messageFormatter = new EmailMessageFormatter($logger);
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'read_inbox'      => $this->readInbox($arguments, $agentId, $userId),
            'list_folders'    => $this->listFolders($agentId, $userId),
            'read_folder'     => $this->readFolder($arguments, $agentId, $userId),
            'create_draft'    => $this->createDraft($arguments, $agentId, $userId),
            'send_email'      => $this->sendEmail($arguments, $agentId, $userId),
            'create_folder'   => $this->createFolder($arguments, $agentId, $userId),
            'rename_folder'   => $this->renameFolder($arguments, $agentId, $userId),
            'delete_folder'   => $this->deleteFolder($arguments, $agentId, $userId),
            'move_email'      => $this->moveEmail($arguments, $agentId, $userId),
            'delete_email'    => $this->deleteEmail($arguments, $agentId, $userId),
            'mark_email_read' => $this->markEmailRead($arguments, $agentId, $userId),
            default           => ToolResult::fail("Unknown email operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        return $this->actionDescriber->describe($this->getOperationName($arguments), $arguments);
    }

    public function readInbox(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $limit      = $this->clampLimit((int) ($arguments['limit'] ?? self::DEFAULT_EMAIL_LIMIT));
        $markAsRead = (bool) ($arguments['mark_as_read'] ?? false);
        $unreadOnly = (bool) ($arguments['unread_only'] ?? false);

        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        try {
            $messages = $this->imapClient->fetchInboxMessages($imapSettings, $limit, $markAsRead, $unreadOnly);
        } catch (Throwable $e) {
            return $this->messageFormatter->formatImapError('Failed to fetch emails', $e);
        }

        if ($messages === []) {
            return ToolResult::ok(
                $unreadOnly ? 'No unread emails in the INBOX.' : 'No recent emails in the INBOX.',
            );
        }

        $header = $unreadOnly ? "Latest Unread Emails:\n\n" : "Latest Emails:\n\n";
        return ToolResult::ok($header . $this->messageFormatter->formatMessageList($messages));
    }

    public function listFolders(int $agentId, ?int $userId): ToolResult
    {
        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        try {
            $names = $this->imapClient->fetchFolderNames($imapSettings);
        } catch (Throwable $e) {
            return $this->messageFormatter->formatImapError('Failed to list folders', $e);
        }

        if ($names === []) {
            return ToolResult::ok('No email folders found.');
        }

        return ToolResult::ok('Available folders: ' . implode(', ', $names));
    }

    public function readFolder(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $folderName = trim((string) ($arguments['folder'] ?? ''));
        $limit      = $this->clampLimit((int) ($arguments['limit'] ?? self::DEFAULT_EMAIL_LIMIT));

        $guard = $folderName !== ''
            ? null
            : ToolResult::fail('Missing required parameter: folder name is required for read_folder.');

        return $guard ?? $this->withImapSettings($agentId, $userId, function (array $imapSettings) use ($folderName, $limit): ToolResult {
            try {
                $messages = $this->imapClient->fetchFolderMessages($imapSettings, $folderName, $limit);
            } catch (Throwable $e) {
                return $this->messageFormatter->formatImapError("Failed to read folder '{$folderName}'", $e);
            }
            if ($messages === []) {
                return ToolResult::ok("No emails found in folder '{$folderName}'.");
            }
            return ToolResult::ok("Emails in {$folderName}:\n\n" . $this->messageFormatter->formatMessageList($messages));
        });
    }

    public function createDraft(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $to      = trim((string) ($arguments['to'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $body    = trim((string) ($arguments['body'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            return ToolResult::fail('Missing required parameters: to, subject, or body.');
        }

        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        $from = (string) ($this->settingsResolver->fetchSettings(static::class, $agentId, $userId)[self::KEY_SMTP_FROM] ?? '');
        $imapSettings['from'] = $from;

        if (!$this->imapClient->saveDraft($imapSettings, $to, $subject, $body)) {
            $this->logger?->error('EmailTool: failed to save draft');
            return ToolResult::fail('Failed to save draft to the Drafts folder. Check IMAP configuration.');
        }

        $draft  = "From: " . ($from ?: '[From address not configured]') . "\n";
        $draft .= "To: {$to}\n";
        $draft .= "Subject: {$subject}\n";
        $draft .= "\n{$body}";

        return ToolResult::ok("Draft saved to Drafts folder:\n\n{$draft}");
    }

    public function sendEmail(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $to      = trim((string) ($arguments['to'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $body    = trim((string) ($arguments['body'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            return ToolResult::fail('Missing required parameters: to, subject, or body.');
        }

        $settings  = $this->settingsResolver->fetchSettings(static::class, $agentId, $userId);
        $smtpCheck = $this->settingsResolver->validateSmtpSettings($settings, $to);
        if ($smtpCheck instanceof ToolResult) {
            return $smtpCheck;
        }

        try {
            $this->dispatchSmtpEmail($settings, $to, $subject, $body);
        } catch (Throwable $e) {
            $this->logger?->error('SMTP Error', ['exception' => $e]);
            return ToolResult::fail('Failed to send email: ' . $e->getMessage());
        }

        $this->logger?->debug('EmailTool: sent', ['to' => $to]);
        return ToolResult::ok("Email successfully sent to {$to}.");
    }

    public function createFolder(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $name = trim((string) ($arguments['new_folder'] ?? ''));
        if ($name === '') {
            return ToolResult::fail('Missing required parameter: new_folder (folder name) is required for create_folder.');
        }

        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        try {
            $existingFolders = $this->imapClient->fetchFolderNames($imapSettings);
        } catch (Throwable $e) {
            return $this->messageFormatter->formatImapError('Failed to fetch folders', $e);
        }

        if (in_array($name, $existingFolders, true)) {
            return ToolResult::ok("Folder '{$name}' already exists.");
        }

        if (!$this->imapClient->createFolder($imapSettings, $name)) {
            return ToolResult::fail("Failed to create folder '{$name}'. Check that the folder name is valid.");
        }

        return ToolResult::ok("Folder '{$name}' created successfully.");
    }

    public function renameFolder(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $oldName = trim((string) ($arguments['folder'] ?? ''));
        $newName = trim((string) ($arguments['new_folder'] ?? ''));

        if ($oldName === '' || $newName === '') {
            return ToolResult::fail('Missing required parameters: folder (old name) and new_folder (new name) are required for rename_folder.');
        }

        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        if (!$this->imapClient->renameFolder($imapSettings, $oldName, $newName)) {
            return ToolResult::fail("Failed to rename folder '{$oldName}' to '{$newName}'. Check that the source folder exists and the new name is valid.");
        }

        return ToolResult::ok("Folder '{$oldName}' renamed to '{$newName}' successfully.");
    }

    public function deleteFolder(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $name = trim((string) ($arguments['folder'] ?? ''));
        if ($name === '') {
            return ToolResult::fail('Missing required parameter: folder name is required for delete_folder.');
        }

        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        try {
            $existingFolders = $this->imapClient->fetchFolderNames($imapSettings);
        } catch (Throwable $e) {
            return $this->messageFormatter->formatImapError('Failed to fetch folders', $e);
        }

        if (!in_array($name, $existingFolders, true)) {
            return ToolResult::ok("Folder '{$name}' does not exist.");
        }

        if (!$this->imapClient->deleteFolder($imapSettings, $name)) {
            return ToolResult::fail("Failed to delete folder '{$name}'. Check that it is not a system folder (e.g. INBOX).");
        }

        return ToolResult::ok("Folder '{$name}' deleted successfully.");
    }

    public function moveEmail(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $uid        = (int) ($arguments['uid'] ?? 0);
        $fromFolder = trim((string) ($arguments['folder'] ?? ''));
        $toFolder   = trim((string) ($arguments['new_folder'] ?? ''));

        $validation = $this->validateUidAndFolders($uid, $fromFolder, $toFolder, 'move_email');
        if ($validation instanceof ToolResult) {
            return $validation;
        }

        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        $newUid = $this->imapClient->moveEmail($imapSettings, $uid, $fromFolder, $toFolder);
        if ($newUid === '') {
            return ToolResult::fail("Failed to move email UID {$uid} from '{$fromFolder}' to '{$toFolder}'. Check that the email exists and both folders are valid.");
        }

        return ToolResult::ok("Email UID {$uid} moved from '{$fromFolder}' to '{$toFolder}' (new UID: {$newUid}) successfully.");
    }

    public function deleteEmail(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $uid    = (int) ($arguments['uid'] ?? 0);
        $folder = trim((string) ($arguments['folder'] ?? ''));

        $validation = $this->validateUidAndFolder($uid, $folder, 'delete_email');
        if ($validation instanceof ToolResult) {
            return $validation;
        }

        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        if (!$this->imapClient->deleteEmail($imapSettings, $uid, $folder)) {
            return ToolResult::fail("Failed to delete email UID {$uid} from '{$folder}'. Check that the email exists and is not a system folder.");
        }

        return ToolResult::ok("Email UID {$uid} deleted from '{$folder}' successfully.");
    }

    public function markEmailRead(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $uid    = (int) ($arguments['uid'] ?? 0);
        $folder = trim((string) ($arguments['folder'] ?? ''));
        $read   = (bool) ($arguments['read'] ?? true);

        $validation = $this->validateUidAndFolder($uid, $folder, 'mark_email_read');
        if ($validation instanceof ToolResult) {
            return $validation;
        }

        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }

        if (!$this->imapClient->setEmailFlag($imapSettings, $uid, $folder, 'Seen', $read)) {
            $label = $read ? 'read' : 'unread';
            return ToolResult::fail("Failed to mark email UID {$uid} as {$label}. Check that the email exists.");
        }

        $label = $read ? 'read' : 'unread';
        return ToolResult::ok("Email UID {$uid} marked as {$label} successfully.");
    }

    /**
     * Resolve effective settings for the tool, plus clamp the requested limit
     * to the supported range. Centralized to keep individual operations terse.
     */
    private function clampLimit(int $limit): int
    {
        if ($limit <= 0 || $limit > self::MAX_EMAIL_LIMIT) {
            return self::DEFAULT_EMAIL_LIMIT;
        }
        return $limit;
    }

    /**
     * Run a callback against a resolved IMAP settings array, returning the
     * resolver's error verbatim if settings are incomplete. Keeps operations
     * to a single return point by hiding the imap-or-fail branch.
     *
     * @param callable(array<string, mixed>): ToolResult $callback
     */
    private function withImapSettings(int $agentId, ?int $userId, callable $callback): ToolResult
    {
        $imapSettings = $this->settingsResolver->resolveImapSettingsOrFail(static::class, $agentId, $userId);
        if ($imapSettings instanceof ToolResult) {
            return $imapSettings;
        }
        return $callback($imapSettings);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function dispatchSmtpEmail(array $settings, string $to, string $subject, string $body): void
    {
        $host       = $settings[self::KEY_SMTP_HOST] ?? '';
        $port       = $settings[self::KEY_SMTP_PORT] ?? '587';
        $encryption = $settings[self::KEY_SMTP_ENCRYPTION] ?? 'tls';
        $user       = $settings[self::KEY_EMAIL_USERNAME] ?? '';
        $pass       = $settings[self::KEY_EMAIL_PASSWORD] ?? '';
        $from       = $settings[self::KEY_SMTP_FROM] ?? '';
        $timeout    = (int) ($settings[self::KEY_SMTP_TIMEOUT] ?? 30);

        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $dsn = sprintf(
            '%s://%s:%s@%s:%d?timeout=%d',
            $scheme,
            rawurlencode($user),
            rawurlencode($pass),
            rawurlencode($host),
            (int) $port,
            $timeout,
        );

        $transport = Transport::fromDsn($dsn);
        $mailer    = new Mailer($transport);

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->text($body);

        $mailer->send($email);
    }

    private function validateUidAndFolder(int $uid, string $folder, string $operation): ?ToolResult
    {
        if ($uid <= 0) {
            return ToolResult::fail("Missing required parameter: uid must be a positive integer for {$operation}.");
        }
        if ($folder === '') {
            return ToolResult::fail("Missing required parameter: folder name is required for {$operation}.");
        }
        return null;
    }

    private function validateUidAndFolders(int $uid, string $fromFolder, string $toFolder, string $operation): ?ToolResult
    {
        if ($uid <= 0) {
            return ToolResult::fail("Missing required parameter: uid must be a positive integer for {$operation}.");
        }
        if ($fromFolder === '' || $toFolder === '') {
            return ToolResult::fail("Missing required parameters: folder (source) and new_folder (destination) are required for {$operation}.");
        }
        return null;
    }
}
