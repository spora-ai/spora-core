<?php

declare(strict_types=1);

namespace Spora\Tools;

use DateTimeImmutable;
use DateTimeInterface;
use stdClass;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(
    name: 'current_time',
    description: 'Returns the exact current date, time, and timezone. Use this whenever you need to know the current date to answer a question or schedule an event.',
    displayName: 'Current Time',
    category: 'productivity',
)]
#[ToolOperation(name: 'now', description: 'Get the current date and time', enabledByDefault: true, requiresApprovalByDefault: false)]
final class CurrentTimeTool implements ToolInterface
{
    use HasOperations;

    public function execute(array $arguments, int $agentId): ToolResult
    {
        return $this->now($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Get current date and time';
    }

    public function now(array $arguments, int $agentId): ToolResult
    {
        $now      = new DateTimeImmutable();
        $iso8601  = $now->format(DateTimeInterface::ATOM);
        $timezone = $now->getTimezone()->getName();
        $unix     = $now->getTimestamp();

        $content = "Current Date & Time: {$iso8601}\nTimezone: {$timezone}\nUnix Timestamp: {$unix}";

        return new ToolResult(true, $content);
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];
    }
}
