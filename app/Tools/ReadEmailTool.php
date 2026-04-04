<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

#[Tool(
    name: 'read_email',
    description: 'Read the latest unread emails from the inbox. Use this to check for new messages, verifications, or replies.',
)]
#[ToolSetting(key: 'core.imap.host', label: 'IMAP Host', type: 'text', description: 'e.g. imap.example.com', scope: 'agent')]
#[ToolSetting(key: 'core.imap.port', label: 'IMAP Port', type: 'text', description: 'Usually 993', scope: 'agent')]
#[ToolSetting(key: 'core.imap.encryption', label: 'IMAP Encryption', type: 'text', description: 'ssl or tls', scope: 'agent')]
#[ToolSetting(key: 'core.imap.username', label: 'IMAP Username', type: 'text', description: 'Email address', scope: 'agent')]
#[ToolSetting(key: 'core.imap.password', label: 'IMAP Password', type: 'password', description: 'Email password or App password', scope: 'agent')]
#[ToolParameter(
    name: 'limit',
    type: 'integer',
    description: 'Maximum number of emails to retrieve (default 5, max 20).',
    required: false,
)]
#[ToolParameter(
    name: 'mark_as_read',
    type: 'boolean',
    description: 'If true, marks fetched emails as read (Seen). Defaults to false. Note: marking as read is irreversible.',
    required: false,
)]
final class ReadEmailTool implements InputToolInterface
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $limit      = (int) ($arguments['limit'] ?? 5);
        // #2: mark_as_read defaults to false — setFlag('Seen') is a destructive/irreversible
        // action that should only happen when the caller explicitly opts in.
        $markAsRead = (bool) ($arguments['mark_as_read'] ?? false);

        if ($limit <= 0 || $limit > 20) {
            $limit = 5;
        }

        $settings   = $this->configService->getEffectiveSettings(static::class, $agentId);
        $host       = $settings['core.imap.host'] ?? '';
        $port       = $settings['core.imap.port'] ?? '';
        $encryption = $settings['core.imap.encryption'] ?? 'ssl';
        $username   = $settings['core.imap.username'] ?? '';
        $password   = $settings['core.imap.password'] ?? '';

        if (empty($host) || empty($username) || empty($password)) {
            return new ToolResult(false, 'IMAP configuration is incomplete. Please check the Read Email settings.');
        }

        try {
            $cm = new ClientManager();
            $client = $cm->make([
                'host'          => $host,
                'port'          => $port ? (int) $port : 993,
                'encryption'    => $encryption,
                'validate_cert' => true,
                'username'      => $username,
                'password'      => $password,
                'protocol'      => 'imap',
            ]);

            $client->connect();
            $folder = $client->getFolder('INBOX');

            // Fetch unseen messages
            $messages = $folder->messages()->unseen()->limit($limit)->get();

            if ($messages->isEmpty()) {
                return new ToolResult(true, 'No new/unread emails in the INBOX.');
            }

            $output = "Latest Unread Emails:\n\n";

            foreach ($messages as $message) {
                $uid     = $message->getUid();
                $subject = $message->getSubject();
                $from    = $message->getFrom()[0]->mail ?? 'Unknown';
                $date    = $message->getDate()?->format('Y-m-d H:i:s') ?? 'Unknown Date';

                // Get text body, fallback to html if strictly needed, though text is preferred for LLMs
                $body = $message->getTextBody();
                if (empty($body)) {
                    $body = strip_tags($message->getHTMLBody() ?? '');
                }

                $output .= "--- [UID: {$uid}] ---\n";
                $output .= "From: {$from}\n";
                $output .= "Date: {$date}\n";
                $output .= "Subject: {$subject}\n";
                $output .= "Body:\n{$body}\n";
                $output .= "---------------------\n\n";

                // Mark as read only if explicitly requested (irreversible operation).
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

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of emails to retrieve.',
                ],
                'mark_as_read' => [
                    'type'        => 'boolean',
                    'description' => 'If true, marks fetched emails as read. Irreversible. Defaults to false.',
                ],
            ],
            'required' => [],
        ];
    }
}
