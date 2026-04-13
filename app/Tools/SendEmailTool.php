<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Throwable;

#[Tool(
    name: 'send_email',
    description: 'Send an email to a recipient. You MUST construct a professional and complete email body. The human will review the email before it is sent.',
    displayName: 'Send Email',
)]
#[ToolSetting(key: 'core.smtp.host', label: 'SMTP Host', type: 'text', description: 'e.g. smtp.example.com', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.smtp.port', label: 'Port', type: 'select', description: 'SMTP port number', scope: 'agent', options: ['25' => '25', '465' => '465', '587' => '587', '2525' => '2525'])]
#[ToolSetting(key: 'core.smtp.username', label: 'Username', type: 'text', description: 'SMTP authentication username', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.smtp.password', label: 'Password', type: 'password', description: 'SMTP authentication password', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.smtp.from', label: 'From Address', type: 'text', description: 'e.g. agent@spora.local', scope: 'agent', required: true)]
#[ToolSetting(key: 'core.smtp.allowed_recipients', label: 'Allowed Recipients', type: 'text', description: 'Comma-separated list of exact email addresses the agent is allowed to send to (or * for all).', scope: 'agent')]
#[ToolSetting(key: 'core.smtp.timeout', label: 'Timeout', type: 'text', description: 'Seconds before an SMTP connection fails (default: 30)', scope: 'agent')]
#[ToolParameter(
    name: 'to',
    type: 'string',
    description: 'The email address of the recipient.',
    required: true,
)]
#[ToolParameter(
    name: 'subject',
    type: 'string',
    description: 'The subject line of the email.',
    required: true,
)]
#[ToolParameter(
    name: 'body',
    type: 'string',
    description: 'The plain text body content of the email.',
    required: true,
)]
final class SendEmailTool implements OutputToolInterface
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $to      = trim((string) ($arguments['to'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $body    = trim((string) ($arguments['body'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            return new ToolResult(false, 'Missing required parameters: to, subject, or body.');
        }

        $settings  = $this->configService->getEffectiveSettings(static::class, $agentId);
        $host      = $settings['core.smtp.host'] ?? '';
        $port      = $settings['core.smtp.port'] ?? '587';
        $user      = $settings['core.smtp.username'] ?? '';
        $pass      = $settings['core.smtp.password'] ?? '';
        $from      = $settings['core.smtp.from'] ?? '';
        $allowedTo = $settings['core.smtp.allowed_recipients'] ?? '';
        $timeout   = (int) ($settings['core.smtp.timeout'] ?? 30);

        if (empty($host) || empty($from)) {
            return new ToolResult(false, 'SMTP configuration is incomplete. Please configure SMTP Host and From Address in settings.');
        }

        // Build DSN from individual fields.
        // rawurlencode is required here — urlencode() encodes spaces as '+' which
        // breaks DSN parsing. Casting port to int guards against non-numeric values.
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?timeout=%d',
            rawurlencode($user),
            rawurlencode($pass),
            rawurlencode($host),
            (int) $port,
            $timeout,
        );

        // Security Barrier: Allowed Recipients check
        if (!empty($allowedTo) && trim($allowedTo) !== '*') {
            $allowedList = array_map('trim', explode(',', $allowedTo));
            if (!in_array($to, $allowedList, true)) {
                return new ToolResult(false, "SECURITY REJECTION: The agent is only permitted to send emails to the allowed recipients config: {$allowedTo}. Cannot send to {$to}");
            }
        }

        try {
            $this->logger?->debug('SendEmailTool: executing', [
                'to' => $to,
                'subject' => $subject,
                'host' => $host,
            ]);

            $transport = Transport::fromDsn($dsn);
            $mailer    = new Mailer($transport);

            $email = (new Email())
                ->from($from)
                ->to($to)
                ->subject($subject)
                ->text($body);

            $mailer->send($email);

            $this->logger?->debug('SendEmailTool: sent', ['to' => $to]);

            return new ToolResult(true, "Email successfully sent to {$to}.");
        } catch (Throwable $e) {
            $this->logger?->error('SMTP Error', ['exception' => $e]);
            return new ToolResult(false, 'Failed to send email: ' . $e->getMessage());
        }
    }

    public function describeAction(array $arguments): string
    {
        $to = $arguments['to'] ?? 'Unknown Recipient';
        $sub = $arguments['subject'] ?? 'No Subject';
        return "Sending email to {$to} with subject: '{$sub}'";
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
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
            'required' => ['to', 'subject', 'body'],
        ];
    }
}
