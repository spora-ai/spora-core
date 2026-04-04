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
)]
#[ToolSetting(key: 'core.smtp.dsn', label: 'SMTP DSN', type: 'password', description: 'e.g. smtp://user:pass@smtp.example.com:587', scope: 'agent')]
#[ToolSetting(key: 'core.smtp.from', label: 'From Address', type: 'text', description: 'e.g. agent@spora.local', scope: 'agent')]
#[ToolSetting(key: 'core.smtp.allowed_recipients', label: 'Allowed Recipients', type: 'text', description: 'Comma-separated list of exact email addresses the agent is allowed to send to (or * for all).', scope: 'agent')]
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
        $dsn       = $settings['core.smtp.dsn'] ?? '';
        $from      = $settings['core.smtp.from'] ?? '';
        $allowedTo = $settings['core.smtp.allowed_recipients'] ?? '';

        if (empty($dsn) || empty($from)) {
            return new ToolResult(false, 'SMTP configuration is incomplete. Please configure SMTP DSN and From Address in settings.');
        }

        // Security Barrier: Allowed Recipients check
        if (!empty($allowedTo) && trim($allowedTo) !== '*') {
            $allowedList = array_map('trim', explode(',', $allowedTo));
            if (!in_array($to, $allowedList, true)) {
                return new ToolResult(false, "SECURITY REJECTION: The agent is only permitted to send emails to the allowed recipients config: {$allowedTo}. Cannot send to {$to}");
            }
        }

        try {
            $transport = Transport::fromDsn($dsn);
            $mailer    = new Mailer($transport);

            $email = (new Email())
                ->from($from)
                ->to($to)
                ->subject($subject)
                ->text($body);

            $mailer->send($email);

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
