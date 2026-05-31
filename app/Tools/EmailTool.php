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
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Throwable;

#[Tool(
    name: 'email',
    description: 'All email operations including reading inbox, listing folders, and sending emails. The "action" argument selects the operation.',
    displayName: 'Email',
    category: 'communication',
)]
#[ToolOperation(name: 'read_inbox', description: 'Read unread emails from the INBOX', enabledByDefault: true, requiresApprovalByDefault: false)]
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
#[ToolSetting(key: 'core.imap.host', label: 'IMAP Host', type: 'text', description: 'e.g. imap.example.com', scope: 'agent')]
#[ToolSetting(key: 'core.imap.port', label: 'IMAP Port', type: 'text', description: 'Usually 993', scope: 'agent', default: '993')]
#[ToolSetting(key: 'core.imap.encryption', label: 'IMAP Encryption', type: 'select', description: 'Encryption method for IMAP', scope: 'agent', default: 'ssl', options: ['ssl' => 'SSL/Implicit TLS', 'tls' => 'TLS/STARTTLS', 'notls' => 'None (not recommended)'])]
#[ToolSetting(key: 'core.email.username', label: 'Email Username', type: 'text', description: 'Email address used for both IMAP and SMTP authentication', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.email.password', label: 'Email Password', type: 'password', description: 'Email password or App password used for both IMAP and SMTP', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.imap.timeout', label: 'IMAP Timeout', type: 'text', description: 'Seconds before an IMAP connection fails (default: 60)', scope: 'agent', default: '60')]
// SMTP settings (for send operations)
#[ToolSetting(key: 'core.smtp.host', label: 'SMTP Host', type: 'text', description: 'e.g. smtp.example.com', scope: 'agent')]
#[ToolSetting(key: 'core.smtp.port', label: 'SMTP Port', type: 'text', description: 'Usually 587 or 465', scope: 'agent', default: '587')]
#[ToolSetting(key: 'core.smtp.encryption', label: 'SMTP Encryption', type: 'select', description: 'Encryption method for SMTP', scope: 'agent', default: 'tls', options: ['ssl' => 'SSL/Implicit TLS', 'tls' => 'TLS/STARTTLS', 'notls' => 'None (not recommended)'])]
#[ToolSetting(key: 'core.smtp.from', label: 'From Address', type: 'text', description: 'e.g. agent@spora.local', scope: 'agent', required: true, exposeToLlm: true)]
#[ToolSetting(key: 'core.smtp.allowed_recipients', label: 'Allowed Recipients', type: 'text', description: 'Comma-separated list of exact email addresses the agent is allowed to send to (or * for all).', scope: 'agent', exposeToLlm: true)]
#[ToolSetting(key: 'core.smtp.timeout', label: 'SMTP Timeout', type: 'text', description: 'Seconds before an SMTP connection fails (default: 30)', scope: 'agent', default: '30')]
// Tool parameters
#[ToolParameter(name: 'action', type: 'string', description: 'The email operation to perform: read_inbox, list_folders, read_folder, create_draft, send_email, create_folder, rename_folder, delete_folder, move_email, delete_email, mark_email_read', required: true, enum: ['read_inbox', 'list_folders', 'read_folder', 'create_draft', 'send_email', 'create_folder', 'rename_folder', 'delete_folder', 'move_email', 'delete_email', 'mark_email_read'])]
#[ToolParameter(name: 'limit', type: 'integer', description: 'Maximum number of emails to retrieve (default 5, max 20). Used with read_inbox.', required: false)]
#[ToolParameter(name: 'mark_as_read', type: 'boolean', description: 'If true, marks fetched emails as read. Irreversible. Defaults to false.', required: false)]
#[ToolParameter(name: 'folder', type: 'string', description: 'The folder name to read from (e.g. INBOX, Sent, Drafts). Used with read_folder.', required: false)]
#[ToolParameter(name: 'to', type: 'string', description: 'The email address of the recipient. Used with send_email.', required: false)]
#[ToolParameter(name: 'subject', type: 'string', description: 'The subject line of the email. Used with send_email.', required: false)]
#[ToolParameter(name: 'body', type: 'string', description: 'The plain text body content of the email. Used with send_email.', required: false)]
#[ToolParameter(name: 'new_folder', type: 'string', description: 'The new folder name. Used with create_folder, rename_folder, and move_email (as destination).', required: false)]
#[ToolParameter(name: 'uid', type: 'integer', description: 'The UID of the email to act on. Used with move_email, delete_email, mark_email_read.', required: false)]
#[ToolParameter(name: 'read', type: 'boolean', description: 'If true, marks the email as read. If false, marks as unread. Defaults to true. Used with mark_email_read.', required: false)]
final class EmailTool implements ToolInterface
{
    use HasOperations;

    // IMAP settings keys
    private const KEY_IMAP_HOST       = 'core.imap.host';
    private const KEY_IMAP_PORT       = 'core.imap.port';
    private const KEY_IMAP_ENCRYPTION = 'core.imap.encryption';
    private const KEY_EMAIL_USERNAME  = 'core.email.username';
    private const KEY_EMAIL_PASSWORD  = 'core.email.password';
    private const KEY_IMAP_TIMEOUT    = 'core.imap.timeout';

    // SMTP settings keys
    private const KEY_SMTP_HOST              = 'core.smtp.host';
    private const KEY_SMTP_PORT              = 'core.smtp.port';
    private const KEY_SMTP_ENCRYPTION        = 'core.smtp.encryption';
    private const KEY_SMTP_FROM              = 'core.smtp.from';
    private const KEY_SMTP_ALLOWED_RECIPIENTS = 'core.smtp.allowed_recipients';
    private const KEY_SMTP_TIMEOUT           = 'core.smtp.timeout';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly ImapClientInterface $imapClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'read_inbox'    => $this->readInbox($arguments, $agentId, $userId),
            'list_folders' => $this->listFolders($arguments, $agentId, $userId),
            'read_folder'   => $this->readFolder($arguments, $agentId, $userId),
            'create_draft' => $this->createDraft($arguments, $agentId, $userId),
            'send_email'   => $this->sendEmail($arguments, $agentId, $userId),
            'create_folder' => $this->createFolder($arguments, $agentId, $userId),
            'rename_folder' => $this->renameFolder($arguments, $agentId, $userId),
            'delete_folder' => $this->deleteFolder($arguments, $agentId, $userId),
            'move_email'   => $this->moveEmail($arguments, $agentId, $userId),
            'delete_email' => $this->deleteEmail($arguments, $agentId, $userId),
            'mark_email_read' => $this->markEmailRead($arguments, $agentId, $userId),
            default        => new ToolResult(false, "Unknown email operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'read_inbox'   => 'Read unread emails from the inbox',
            'list_folders' => 'List all email folders',
            'read_folder'  => 'Read emails from a specific folder',
            'create_draft' => 'Save an email draft to the Drafts folder',
            'send_email'   => $this->describeSendEmail($arguments),
            'create_folder' => $this->describeCreateFolder($arguments),
            'rename_folder' => $this->describeRenameFolder($arguments),
            'delete_folder' => $this->describeDeleteFolder($arguments),
            'move_email'   => $this->describeMoveEmail($arguments),
            'delete_email' => $this->describeDeleteEmail($arguments),
            'mark_email_read' => $this->describeMarkEmailRead($arguments),
            default        => 'Perform an email operation',
        };
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'The email operation to perform: read_inbox, list_folders, read_folder, create_draft, send_email, create_folder, rename_folder, delete_folder, move_email, delete_email, mark_email_read',
                    'enum'        => ['read_inbox', 'list_folders', 'read_folder', 'create_draft', 'send_email', 'create_folder', 'rename_folder', 'delete_folder', 'move_email', 'delete_email', 'mark_email_read'],
                ],
                // read_inbox parameters
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of emails to retrieve (default 5, max 20). Used with read_inbox.',
                ],
                'mark_as_read' => [
                    'type'        => 'boolean',
                    'description' => 'If true, marks fetched emails as read. Irreversible. Defaults to false.',
                ],
                // read_folder parameter
                'folder' => [
                    'type'        => 'string',
                    'description' => 'The folder name to read from, rename from, delete, move from, or act on. E.g. INBOX, Sent, Drafts.',
                ],
                // create_draft / send_email parameters
                'to' => [
                    'type'        => 'string',
                    'description' => 'The email address of the recipient.',
                ],
                'subject' => [
                    'type'        => 'string',
                    'description' => 'The subject line of the email.',
                ],
                'body' => [
                    'type'        => 'string',
                    'description' => 'The plain text body content of the email.',
                ],
                // create_folder / rename_folder / move_email (dest) parameters
                'new_folder' => [
                    'type'        => 'string',
                    'description' => 'The new folder name for create_folder, rename_folder, or the destination folder for move_email.',
                ],
                // move_email / delete_email / mark_email_read parameters
                'uid' => [
                    'type'        => 'integer',
                    'description' => 'The UID of the email to move, delete, or mark read/unread.',
                ],
                // mark_email_read parameter
                'read' => [
                    'type'        => 'boolean',
                    'description' => 'If true, marks as read. If false, marks as unread. Defaults to true. Used with mark_email_read.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    // ── Operations ──────────────────────────────────────────────────────────────

    public function readInbox(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $limit      = (int) ($arguments['limit'] ?? 5);
        $markAsRead = (bool) ($arguments['mark_as_read'] ?? false);

        if ($limit <= 0 || $limit > 20) {
            $limit = 5;
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        try {
            $messages = $this->imapClient->fetchInboxMessages($imapSettings, $limit, $markAsRead);

            if ($messages === []) {
                return new ToolResult(true, 'No new/unread emails in the INBOX.');
            }

            $output = "Latest Unread Emails:\n\n";
            foreach ($messages as $msg) {
                $output .= "--- [UID: {$msg['uid']}] ---\n";
                $output .= "From: {$msg['from']}\n";
                $output .= "Date: {$msg['date']}\n";
                $output .= "Subject: {$msg['subject']}\n";
                $output .= "Body:\n{$msg['body']}\n";
                $output .= "---------------------\n\n";
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('IMAP Error', ['exception' => $e]);
            return new ToolResult(false, 'Failed to fetch emails: ' . $e->getMessage());
        }
    }

    public function listFolders(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        try {
            $names = $this->imapClient->fetchFolderNames($imapSettings);

            if ($names === []) {
                return new ToolResult(true, 'No email folders found.');
            }

            return new ToolResult(true, 'Available folders: ' . implode(', ', $names));
        } catch (Throwable $e) {
            $this->logger?->error('IMAP Error', ['exception' => $e]);
            return new ToolResult(false, 'Failed to list folders: ' . $e->getMessage());
        }
    }

    public function readFolder(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $folderName = trim((string) ($arguments['folder'] ?? ''));
        $limit      = (int) ($arguments['limit'] ?? 5);

        if ($folderName === '') {
            return new ToolResult(false, 'Missing required parameter: folder name is required for read_folder.');
        }

        if ($limit <= 0 || $limit > 20) {
            $limit = 5;
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        try {
            $messages = $this->imapClient->fetchFolderMessages($imapSettings, $folderName, $limit);

            if ($messages === []) {
                return new ToolResult(true, "No emails found in folder '{$folderName}'.");
            }

            $output = "Emails in {$folderName}:\n\n";
            foreach ($messages as $msg) {
                $output .= "--- [UID: {$msg['uid']}] ---\n";
                $output .= "From: {$msg['from']}\n";
                $output .= "Date: {$msg['date']}\n";
                $output .= "Subject: {$msg['subject']}\n";
                $output .= "Body:\n{$msg['body']}\n";
                $output .= "---------------------\n\n";
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('IMAP Error', ['exception' => $e]);
            return new ToolResult(false, "Failed to read folder '{$folderName}': " . $e->getMessage());
        }
    }

    public function createDraft(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $to      = trim((string) ($arguments['to'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $body    = trim((string) ($arguments['body'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            return new ToolResult(false, 'Missing required parameters: to, subject, or body.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        $from = $settings[self::KEY_SMTP_FROM] ?? '';
        $imapSettings['from'] = $from;

        $success = $this->imapClient->saveDraft($imapSettings, $to, $subject, $body);

        if (!$success) {
            $this->logger?->error('EmailTool: failed to save draft');
            return new ToolResult(false, 'Failed to save draft to the Drafts folder. Check IMAP configuration.');
        }

        $draft  = "From: " . ($from ?: '[From address not configured]') . "\n";
        $draft .= "To: {$to}\n";
        $draft .= "Subject: {$subject}\n";
        $draft .= "\n{$body}";

        return new ToolResult(true, "Draft saved to Drafts folder:\n\n{$draft}");
    }

    public function sendEmail(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $to      = trim((string) ($arguments['to'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $body    = trim((string) ($arguments['body'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            return new ToolResult(false, 'Missing required parameters: to, subject, or body.');
        }

        $settings    = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $host        = $settings[self::KEY_SMTP_HOST] ?? '';
        $port        = $settings[self::KEY_SMTP_PORT] ?? '587';
        $encryption  = $settings[self::KEY_SMTP_ENCRYPTION] ?? 'tls';
        $user        = $settings[self::KEY_EMAIL_USERNAME] ?? '';
        $pass        = $settings[self::KEY_EMAIL_PASSWORD] ?? '';
        $from        = $settings[self::KEY_SMTP_FROM] ?? '';
        $allowedTo   = $settings[self::KEY_SMTP_ALLOWED_RECIPIENTS] ?? '';
        $timeout     = (int) ($settings[self::KEY_SMTP_TIMEOUT] ?? 30);

        if (empty($host) || empty($from)) {
            return new ToolResult(false, 'SMTP configuration is incomplete. Please configure SMTP Host and From Address in settings.');
        }

        // Security Barrier: Allowed Recipients check
        if (!empty($allowedTo) && trim($allowedTo) !== '*') {
            $allowedList = array_map('trim', explode(',', $allowedTo));
            if (!in_array($to, $allowedList, true)) {
                return new ToolResult(false, "SECURITY REJECTION: The agent is only permitted to send emails to: {$allowedTo}. Cannot send to {$to}");
            }
        }

        try {
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

            $this->logger?->debug('EmailTool: sent', ['to' => $to]);

            return new ToolResult(true, "Email successfully sent to {$to}.");

        } catch (Throwable $e) {
            $this->logger?->error('SMTP Error', ['exception' => $e]);
            return new ToolResult(false, 'Failed to send email: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{host: string, port: string, encryption: string, username: string, password: string, timeout: string}|null
     */
    private function resolveImapSettings(array $settings): array|null
    {
        $host = $settings[self::KEY_IMAP_HOST] ?? '';
        $port = $settings[self::KEY_IMAP_PORT] ?? '993';
        $enc  = $settings[self::KEY_IMAP_ENCRYPTION] ?? 'ssl';
        $user = $settings[self::KEY_EMAIL_USERNAME] ?? '';
        $pass = $settings[self::KEY_EMAIL_PASSWORD] ?? '';
        $timeout = (string) ($settings[self::KEY_IMAP_TIMEOUT] ?? '60');

        if ($host === '' || $user === '' || $pass === '') {
            return null;
        }

        return [
            'host'      => $host,
            'port'      => (string) $port,
            'encryption' => $enc,
            'username'  => $user,
            'password'  => $pass,
            'timeout'   => $timeout,
        ];
    }

    private function describeSendEmail(array $arguments): string
    {
        $to = $arguments['to'] ?? 'Unknown Recipient';
        $sub = $arguments['subject'] ?? 'No Subject';
        return "Sending email to {$to} with subject: '{$sub}'";
    }

    private function describeCreateFolder(array $arguments): string
    {
        $name = $arguments['new_folder'] ?? '[folder name]';
        return "Create email folder '{$name}'";
    }

    private function describeRenameFolder(array $arguments): string
    {
        $from = $arguments['folder'] ?? '[folder]';
        $to = $arguments['new_folder'] ?? '[new name]';
        return "Rename email folder '{$from}' to '{$to}'";
    }

    private function describeDeleteFolder(array $arguments): string
    {
        $name = $arguments['folder'] ?? '[folder]';
        return "Delete email folder '{$name}'";
    }

    private function describeMoveEmail(array $arguments): string
    {
        $uid = $arguments['uid'] ?? '[uid]';
        $from = $arguments['folder'] ?? '[folder]';
        $to = $arguments['new_folder'] ?? '[folder]';
        return "Move email UID {$uid} from '{$from}' to '{$to}'";
    }

    private function describeDeleteEmail(array $arguments): string
    {
        $uid = $arguments['uid'] ?? '[uid]';
        $folder = $arguments['folder'] ?? '[folder]';
        return "Delete email UID {$uid} from '{$folder}'";
    }

    private function describeMarkEmailRead(array $arguments): string
    {
        $uid = $arguments['uid'] ?? '[uid]';
        $read = ($arguments['read'] ?? true) ? 'read' : 'unread';
        return "Mark email UID {$uid} as {$read}";
    }

    // ── Folder & Email Management Operations ──────────────────────────────────────

    public function createFolder(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $name = trim((string) ($arguments['new_folder'] ?? ''));

        if ($name === '') {
            return new ToolResult(false, 'Missing required parameter: new_folder (folder name) is required for create_folder.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        try {
            $existingFolders = $this->imapClient->fetchFolderNames($imapSettings);
            if (in_array($name, $existingFolders, true)) {
                return new ToolResult(true, "Folder '{$name}' already exists.");
            }
        } catch (Throwable $e) {
            $this->logger?->error('IMAP Error', ['exception' => $e]);
            return new ToolResult(false, 'Failed to fetch folders: ' . $e->getMessage());
        }

        $success = $this->imapClient->createFolder($imapSettings, $name);

        if (!$success) {
            return new ToolResult(false, "Failed to create folder '{$name}'. Check that the folder name is valid.");
        }

        return new ToolResult(true, "Folder '{$name}' created successfully.");
    }

    public function renameFolder(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $oldName = trim((string) ($arguments['folder'] ?? ''));
        $newName = trim((string) ($arguments['new_folder'] ?? ''));

        if ($oldName === '' || $newName === '') {
            return new ToolResult(false, 'Missing required parameters: folder (old name) and new_folder (new name) are required for rename_folder.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        $success = $this->imapClient->renameFolder($imapSettings, $oldName, $newName);

        if (!$success) {
            return new ToolResult(false, "Failed to rename folder '{$oldName}' to '{$newName}'. Check that the source folder exists and the new name is valid.");
        }

        return new ToolResult(true, "Folder '{$oldName}' renamed to '{$newName}' successfully.");
    }

    public function deleteFolder(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $name = trim((string) ($arguments['folder'] ?? ''));

        if ($name === '') {
            return new ToolResult(false, 'Missing required parameter: folder name is required for delete_folder.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        try {
            $existingFolders = $this->imapClient->fetchFolderNames($imapSettings);
            if (!in_array($name, $existingFolders, true)) {
                return new ToolResult(true, "Folder '{$name}' does not exist.");
            }
        } catch (Throwable $e) {
            $this->logger?->error('IMAP Error', ['exception' => $e]);
            return new ToolResult(false, 'Failed to fetch folders: ' . $e->getMessage());
        }

        $success = $this->imapClient->deleteFolder($imapSettings, $name);

        if (!$success) {
            return new ToolResult(false, "Failed to delete folder '{$name}'. Check that it is not a system folder (e.g. INBOX).");
        }

        return new ToolResult(true, "Folder '{$name}' deleted successfully.");
    }

    public function moveEmail(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $uid = (int) ($arguments['uid'] ?? 0);
        $fromFolder = trim((string) ($arguments['folder'] ?? ''));
        $toFolder = trim((string) ($arguments['new_folder'] ?? ''));

        if ($uid <= 0) {
            return new ToolResult(false, 'Missing required parameter: uid must be a positive integer for move_email.');
        }
        if ($fromFolder === '' || $toFolder === '') {
            return new ToolResult(false, 'Missing required parameters: folder (source) and new_folder (destination) are required for move_email.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        $newUid = $this->imapClient->moveEmail($imapSettings, $uid, $fromFolder, $toFolder);

        if ($newUid === '') {
            return new ToolResult(false, "Failed to move email UID {$uid} from '{$fromFolder}' to '{$toFolder}'. Check that the email exists and both folders are valid.");
        }

        return new ToolResult(true, "Email UID {$uid} moved from '{$fromFolder}' to '{$toFolder}' (new UID: {$newUid}) successfully.");
    }

    public function deleteEmail(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $uid = (int) ($arguments['uid'] ?? 0);
        $folder = trim((string) ($arguments['folder'] ?? ''));

        if ($uid <= 0) {
            return new ToolResult(false, 'Missing required parameter: uid must be a positive integer for delete_email.');
        }
        if ($folder === '') {
            return new ToolResult(false, 'Missing required parameter: folder name is required for delete_email.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        $success = $this->imapClient->deleteEmail($imapSettings, $uid, $folder);

        if (!$success) {
            return new ToolResult(false, "Failed to delete email UID {$uid} from '{$folder}'. Check that the email exists and is not a system folder.");
        }

        return new ToolResult(true, "Email UID {$uid} deleted from '{$folder}' successfully.");
    }

    public function markEmailRead(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $uid = (int) ($arguments['uid'] ?? 0);
        $folder = trim((string) ($arguments['folder'] ?? ''));
        $read = (bool) ($arguments['read'] ?? true);

        if ($uid <= 0) {
            return new ToolResult(false, 'Missing required parameter: uid must be a positive integer for mark_email_read.');
        }
        if ($folder === '') {
            return new ToolResult(false, 'Missing required parameter: folder name is required for mark_email_read.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $imapSettings = $this->resolveImapSettings($settings);

        if ($imapSettings === null) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        $success = $this->imapClient->setEmailFlag($imapSettings, $uid, $folder, 'Seen', $read);

        if (!$success) {
            return new ToolResult(false, "Failed to mark email UID {$uid} as " . ($read ? 'read' : 'unread') . ". Check that the email exists.");
        }

        return new ToolResult(true, "Email UID {$uid} marked as " . ($read ? 'read' : 'unread') . " successfully.");
    }
}
