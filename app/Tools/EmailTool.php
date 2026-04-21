<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
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
use Webklex\PHPIMAP\ClientManager;

#[Tool(
    name: 'email',
    description: 'All email operations including reading inbox, listing folders, and sending emails. The "action" argument selects the operation.',
    displayName: 'Email',
    category: 'communication',
)]
#[ToolOperation(name: 'read_inbox',     description: 'Read unread emails from the INBOX',                        enabledByDefault: true,  requiresApprovalByDefault: false)]
#[ToolOperation(name: 'list_folders',  description: 'List all available email folders',                         enabledByDefault: true,  requiresApprovalByDefault: false)]
#[ToolOperation(name: 'read_folder',   description: 'Read emails from a specific folder by name',               enabledByDefault: true,  requiresApprovalByDefault: false)]
#[ToolOperation(name: 'create_draft',  description: 'Prepare an email draft (shows the composed email for review)', enabledByDefault: false, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'send_email',    description: 'Send an email to a recipient',                            enabledByDefault: false, requiresApprovalByDefault: true)]
// IMAP settings (for read operations)
#[ToolSetting(key: 'core.imap.host', label: 'IMAP Host', type: 'text', description: 'e.g. imap.example.com', scope: 'agent')]
#[ToolSetting(key: 'core.imap.port', label: 'IMAP Port', type: 'text', description: 'Usually 993', scope: 'agent')]
#[ToolSetting(key: 'core.imap.encryption', label: 'IMAP Encryption', type: 'select', description: 'Encryption method for IMAP', scope: 'agent', options: ['ssl' => 'SSL', 'tls' => 'TLS', 'notls' => 'None (not recommended)'])]
#[ToolSetting(key: 'core.email.username', label: 'Email Username', type: 'text', description: 'Email address used for both IMAP and SMTP authentication', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.email.password', label: 'Email Password', type: 'password', description: 'Email password or App password used for both IMAP and SMTP', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.imap.timeout', label: 'IMAP Timeout', type: 'text', description: 'Seconds before an IMAP connection fails (default: 60)', scope: 'agent')]
// SMTP settings (for send operations)
#[ToolSetting(key: 'core.smtp.host', label: 'SMTP Host', type: 'text', description: 'e.g. smtp.example.com', scope: 'agent')]
#[ToolSetting(key: 'core.smtp.port', label: 'SMTP Port', type: 'select', description: 'SMTP port number', scope: 'agent', options: ['25' => '25', '465' => '465', '587' => '587', '2525' => '2525'])]
#[ToolSetting(key: 'core.smtp.from', label: 'From Address', type: 'text', description: 'e.g. agent@spora.local', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.smtp.allowed_recipients', label: 'Allowed Recipients', type: 'text', description: 'Comma-separated list of exact email addresses the agent is allowed to send to (or * for all).', scope: 'agent')]
#[ToolSetting(key: 'core.smtp.timeout', label: 'SMTP Timeout', type: 'text', description: 'Seconds before an SMTP connection fails (default: 30)', scope: 'agent')]
// Tool parameters
#[ToolParameter(name: 'action', type: 'string', description: 'The email operation to perform: read_inbox, list_folders, read_folder, create_draft, send_email', required: true, enum: ['read_inbox', 'list_folders', 'read_folder', 'create_draft', 'send_email'])]
#[ToolParameter(name: 'limit', type: 'integer', description: 'Maximum number of emails to retrieve (default 5, max 20). Used with read_inbox.', required: false)]
#[ToolParameter(name: 'mark_as_read', type: 'boolean', description: 'If true, marks fetched emails as read. Irreversible. Defaults to false.', required: false)]
#[ToolParameter(name: 'folder', type: 'string', description: 'The folder name to read from (e.g. INBOX, Sent, Drafts). Used with read_folder.', required: false)]
#[ToolParameter(name: 'to', type: 'string', description: 'The email address of the recipient. Used with send_email.', required: false)]
#[ToolParameter(name: 'subject', type: 'string', description: 'The subject line of the email. Used with send_email.', required: false)]
#[ToolParameter(name: 'body', type: 'string', description: 'The plain text body content of the email. Used with send_email.', required: false)]
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
    private const KEY_SMTP_FROM              = 'core.smtp.from';
    private const KEY_SMTP_ALLOWED_RECIPIENTS = 'core.smtp.allowed_recipients';
    private const KEY_SMTP_TIMEOUT           = 'core.smtp.timeout';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'read_inbox'    => $this->readInbox($arguments, $agentId),
            'list_folders' => $this->listFolders($arguments, $agentId),
            'read_folder'   => $this->readFolder($arguments, $agentId),
            'create_draft' => $this->createDraft($arguments, $agentId),
            'send_email'   => $this->sendEmail($arguments, $agentId),
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
            'create_draft' => 'Prepare an email draft for review',
            'send_email'   => $this->describeSendEmail($arguments),
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
                    'description' => 'The email operation to perform: read_inbox, list_folders, read_folder, create_draft, send_email',
                    'enum'        => ['read_inbox', 'list_folders', 'read_folder', 'create_draft', 'send_email'],
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
                    'description' => 'The folder name to read from (e.g. INBOX, Sent, Drafts). Used with read_folder.',
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
            ],
            'required' => ['action'],
        ];
    }

    // ── Operations ──────────────────────────────────────────────────────────────

    public function readInbox(array $arguments, int $agentId): ToolResult
    {
        $limit      = (int) ($arguments['limit'] ?? 5);
        $markAsRead = (bool) ($arguments['mark_as_read'] ?? false);

        if ($limit <= 0 || $limit > 20) {
            $limit = 5;
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $host     = $settings[self::KEY_IMAP_HOST] ?? '';
        $port     = $settings[self::KEY_IMAP_PORT] ?? '';
        $enc      = $settings[self::KEY_IMAP_ENCRYPTION] ?? 'ssl';
        $user     = $settings[self::KEY_EMAIL_USERNAME] ?? '';
        $pass     = $settings[self::KEY_EMAIL_PASSWORD] ?? '';
        $timeout  = (int) ($settings[self::KEY_IMAP_TIMEOUT] ?? 60);

        if (empty($host) || empty($user) || empty($pass)) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        try {
            $cm = new ClientManager();
            $client = $cm->make([
                'host'          => $host,
                'port'          => $port ? (int) $port : 993,
                'encryption'    => $enc,
                'validate_cert' => true,
                'username'      => $user,
                'password'      => $pass,
                'protocol'      => 'imap',
                'timeout'       => $timeout,
            ]);

            $client->connect();
            $folder = $client->getFolder('INBOX');
            $messages = $folder->messages()->unseen()->limit($limit)->get();

            if ($messages->isEmpty()) {
                $client->disconnect();
                return new ToolResult(true, 'No new/unread emails in the INBOX.');
            }

            $output = "Latest Unread Emails:\n\n";

            foreach ($messages as $message) {
                $uid     = $message->getUid();
                $subject = $message->getSubject();
                $from    = $message->getFrom()[0]->mail ?? 'Unknown';
                $date    = $message->getDate()?->format('Y-m-d H:i:s') ?? 'Unknown Date';
                $body    = $message->getTextBody();
                if (empty($body)) {
                    $body = strip_tags($message->getHTMLBody() ?? '');
                }

                $output .= "--- [UID: {$uid}] ---\n";
                $output .= "From: {$from}\n";
                $output .= "Date: {$date}\n";
                $output .= "Subject: {$subject}\n";
                $output .= "Body:\n{$body}\n";
                $output .= "---------------------\n\n";

                if ($markAsRead) {
                    $message->setFlag('Seen');
                }
            }

            $client->disconnect();

            return new ToolResult(true, $output);

        } catch (Throwable $e) {
            $this->logger?->error('IMAP Error', ['exception' => $e]);
            return new ToolResult(false, 'Failed to fetch emails: ' . $e->getMessage());
        }
    }

    public function listFolders(array $arguments, int $agentId): ToolResult
    {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $host     = $settings[self::KEY_IMAP_HOST] ?? '';
        $port     = $settings[self::KEY_IMAP_PORT] ?? '';
        $enc      = $settings[self::KEY_IMAP_ENCRYPTION] ?? 'ssl';
        $user     = $settings[self::KEY_EMAIL_USERNAME] ?? '';
        $pass     = $settings[self::KEY_EMAIL_PASSWORD] ?? '';
        $timeout  = (int) ($settings[self::KEY_IMAP_TIMEOUT] ?? 60);

        if (empty($host) || empty($user) || empty($pass)) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        try {
            $cm = new ClientManager();
            $client = $cm->make([
                'host'          => $host,
                'port'          => $port ? (int) $port : 993,
                'encryption'    => $enc,
                'validate_cert' => true,
                'username'      => $user,
                'password'      => $pass,
                'protocol'      => 'imap',
                'timeout'       => $timeout,
            ]);

            $client->connect();
            $folders = $client->getFolders();
            $client->disconnect();

            $names = array_map(fn($f) => $f->getName(), $folders->all());
            sort($names);

            return new ToolResult(true, 'Available folders: ' . implode(', ', $names));

        } catch (Throwable $e) {
            $this->logger?->error('IMAP Error', ['exception' => $e]);
            return new ToolResult(false, 'Failed to list folders: ' . $e->getMessage());
        }
    }

    public function readFolder(array $arguments, int $agentId): ToolResult
    {
        $folderName = trim((string) ($arguments['folder'] ?? ''));
        $limit      = (int) ($arguments['limit'] ?? 5);

        if ($folderName === '') {
            return new ToolResult(false, 'Missing required parameter: folder name is required for read_folder.');
        }

        if ($limit <= 0 || $limit > 20) {
            $limit = 5;
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $host     = $settings[self::KEY_IMAP_HOST] ?? '';
        $port     = $settings[self::KEY_IMAP_PORT] ?? '';
        $enc      = $settings[self::KEY_IMAP_ENCRYPTION] ?? 'ssl';
        $user     = $settings[self::KEY_EMAIL_USERNAME] ?? '';
        $pass     = $settings[self::KEY_EMAIL_PASSWORD] ?? '';
        $timeout  = (int) ($settings[self::KEY_IMAP_TIMEOUT] ?? 60);

        if (empty($host) || empty($user) || empty($pass)) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please configure IMAP settings.');
        }

        try {
            $cm = new ClientManager();
            $client = $cm->make([
                'host'          => $host,
                'port'          => $port ? (int) $port : 993,
                'encryption'    => $enc,
                'validate_cert' => true,
                'username'      => $user,
                'password'      => $pass,
                'protocol'      => 'imap',
                'timeout'       => $timeout,
            ]);

            $client->connect();
            $folder = $client->getFolder($folderName);
            $messages = $folder->messages()->limit($limit)->get();

            if ($messages->isEmpty()) {
                $client->disconnect();
                return new ToolResult(true, "No emails found in folder '{$folderName}'.");
            }

            $output = "Emails in {$folderName}:\n\n";

            foreach ($messages as $message) {
                $uid     = $message->getUid();
                $subject = $message->getSubject();
                $from    = $message->getFrom()[0]->mail ?? 'Unknown';
                $date    = $message->getDate()?->format('Y-m-d H:i:s') ?? 'Unknown Date';
                $body    = $message->getTextBody();
                if (empty($body)) {
                    $body = strip_tags($message->getHTMLBody() ?? '');
                }

                $output .= "--- [UID: {$uid}] ---\n";
                $output .= "From: {$from}\n";
                $output .= "Date: {$date}\n";
                $output .= "Subject: {$subject}\n";
                $output .= "Body:\n{$body}\n";
                $output .= "---------------------\n\n";
            }

            $client->disconnect();

            return new ToolResult(true, $output);

        } catch (Throwable $e) {
            $this->logger?->error('IMAP Error', ['exception' => $e]);
            return new ToolResult(false, "Failed to read folder '{$folderName}': " . $e->getMessage());
        }
    }

    public function createDraft(array $arguments, int $agentId): ToolResult
    {
        $to      = trim((string) ($arguments['to'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $body    = trim((string) ($arguments['body'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            return new ToolResult(false, 'Missing required parameters: to, subject, or body.');
        }

        $settings  = $this->configService->getEffectiveSettings(static::class, $agentId);
        $from      = $settings[self::KEY_SMTP_FROM] ?? '';

        $draft  = "From: " . ($from ?: '[From address not configured]') . "\n";
        $draft .= "To: {$to}\n";
        $draft .= "Subject: {$subject}\n";
        $draft .= "\n{$body}";

        return new ToolResult(true, "Email draft prepared:\n\n{$draft}");
    }

    public function sendEmail(array $arguments, int $agentId): ToolResult
    {
        $to      = trim((string) ($arguments['to'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $body    = trim((string) ($arguments['body'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            return new ToolResult(false, 'Missing required parameters: to, subject, or body.');
        }

        $settings    = $this->configService->getEffectiveSettings(static::class, $agentId);
        $host        = $settings[self::KEY_SMTP_HOST] ?? '';
        $port        = $settings[self::KEY_SMTP_PORT] ?? '587';
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
            $dsn = sprintf(
                'smtp://%s:%s@%s:%d?timeout=%d',
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

    private function describeSendEmail(array $arguments): string
    {
        $to = $arguments['to'] ?? 'Unknown Recipient';
        $sub = $arguments['subject'] ?? 'No Subject';
        return "Sending email to {$to} with subject: '{$sub}'";
    }
}
