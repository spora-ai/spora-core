<?php

declare(strict_types=1);

namespace Spora\Tools\Email;

use Psr\Log\LoggerInterface;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Centralized IMAP error message formatting and message-list rendering for
 * the Email tool. Keeps public methods of EmailTool free of presentation
 * details.
 */
final class EmailMessageFormatter
{
    private const LOG_IMAP_ERROR = 'IMAP Error';

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    public function formatImapError(string $prefix, Throwable $e): ToolResult
    {
        $this->logger?->error(self::LOG_IMAP_ERROR, ['exception' => $e]);
        return new ToolResult(false, $prefix . ': ' . $e->getMessage());
    }

    /**
     * @param list<array{uid: string, subject: string, from: string, date: string, body: string}> $messages
     */
    public function formatMessageList(array $messages): string
    {
        $output = '';
        foreach ($messages as $msg) {
            $output .= "--- [UID: {$msg['uid']}] ---\n";
            $output .= "From: {$msg['from']}\n";
            $output .= "Date: {$msg['date']}\n";
            $output .= "Subject: {$msg['subject']}\n";
            $output .= "Body:\n{$msg['body']}\n";
            $output .= "---------------------\n\n";
        }
        return $output;
    }
}
