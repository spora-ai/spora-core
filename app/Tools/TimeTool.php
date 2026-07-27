<?php

declare(strict_types=1);

namespace Spora\Tools;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Get the current date and time, or format an arbitrary Unix epoch in a chosen
 * IANA timezone.
 *
 * Operations:
 *   - 'now'    — current instant in the server's default timezone
 *                (no parameters; returns {datetime, timezone, epoch, weekday}).
 *   - 'format' — render an epoch as a human-readable datetime in a named
 *                IANA timezone. Used by the time-arithmetic skill to
 *                convert a previously-computed epoch back to a string the
 *                agent can speak. Returns {formatted, weekday}.
 *
 * Both ops include `weekday` (long English name, ISO 8601 Monday-based —
 * `Monday`..`Sunday`). The skill teaches the agent to derive the short
 * three-letter form (`Mon`..`Sun`) and numeric day-of-week (`1`..`7`) from
 * this string.
 *
 * The `format` op accepts a user-supplied `timezone` (IANA name) so the agent
 * can answer "what time is it in Tokyo right now" without doing mental
 * arithmetic on offsets. DST is handled by DateTimeZone.
 *
 * BC note: as of v2.1 the `format` op's `$content` is the JSON-encoded
 * payload `{formatted, weekday}` instead of a bare rendered string. Callers
 * that read `$content` directly must decode it. `$data` is also reshaped
 * (no more `datetime`/`format` keys; `formatted` replaces both).
 */
#[Tool(
    name: 'time',
    displayName: 'Time',
    category: 'productivity',
    icon: 'clock',
    description: 'Get the current date and time, or format an arbitrary Unix epoch in a chosen IANA timezone. '
               . 'Use `now` for the current instant; use `format` to convert a previously-computed epoch '
               . '(e.g. from the time-arithmetic skill) back to a human-readable string in a target timezone.',
)]
#[ToolOperation(name: 'now', description: 'Get the current date and time in the server\'s default timezone.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'format', description: 'Format a Unix epoch as a human-readable datetime in a given IANA timezone.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolParameter(name: 'epoch', type: 'integer', description: 'Unix timestamp in seconds. Required only when action is "format"; ignored when action is "now".', required: ['format'], minimum: 0)]
#[ToolParameter(name: 'timezone', type: 'string', description: 'IANA timezone name (e.g. "UTC", "America/New_York", "Asia/Tokyo"). Only used when action is "format". Defaults to "UTC".', required: false, default: 'UTC')]
#[ToolParameter(name: 'format', type: 'string', description: 'Output format: "iso8601" (default), "rfc2822", or "human" ("2026-07-26 14:32:00 UTC"). Only used when action is "format".', required: false, default: 'iso8601', enum: ['iso8601', 'rfc2822', 'human'])]
final class TimeTool extends AbstractTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return match ($this->getOperationName($arguments)) {
            'now'    => $this->doNow(),
            'format' => $this->doFormat($arguments),
            default  => new ToolResult(false, "Unknown operation '{$this->getOperationName($arguments)}'."),
        };
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'now'    => 'Get current date and time',
            'format' => 'Format epoch ' . ($arguments['epoch'] ?? '?') . ' as datetime',
            default  => 'Use the time tool',
        };
    }

    public function now(array $arguments): ToolResult // NOSONAR php:S1172 — required by HasOperations dispatch trait
    {
        return $this->doNow();
    }

    public function format(array $arguments): ToolResult // NOSONAR php:S1172 — required by HasOperations dispatch trait
    {
        return $this->doFormat($arguments);
    }

    private function doNow(): ToolResult
    {
        $now      = new DateTimeImmutable();
        $iso8601  = $now->format(DateTimeInterface::ATOM);
        $timezone = $now->getTimezone()->getName();
        $unix     = $now->getTimestamp();
        $weekday  = $now->format('l');

        return new ToolResult(
            true,
            "Current Date & Time: {$iso8601}\nWeekday: {$weekday}\nTimezone: {$timezone}\nUnix Timestamp: {$unix}",
            ['datetime' => $iso8601, 'timezone' => $timezone, 'epoch' => $unix, 'weekday' => $weekday],
        );
    }

    private function doFormat(array $arguments): ToolResult
    {
        $epoch = $arguments['epoch'] ?? null;
        if (!is_int($epoch) || $epoch < 0) {
            return new ToolResult(
                false,
                'epoch is required and must be a non-negative integer.',
                ['error_code' => 'EPOCH_INVALID'],
            );
        }

        $timezone = (string) ($arguments['timezone'] ?? 'UTC');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            return new ToolResult(
                false,
                "Unknown IANA timezone '{$timezone}'.",
                ['error_code' => 'TIMEZONE_UNKNOWN'],
            );
        }

        $format = (string) ($arguments['format'] ?? 'iso8601');
        $dt = (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone($timezone));
        $rendered = match ($format) {
            'iso8601' => $dt->format(DateTimeInterface::ATOM),
            'rfc2822' => $dt->format(DateTimeInterface::RFC2822),
            'human'   => $dt->format('Y-m-d H:i:s ') . $timezone,
            default   => $dt->format(DateTimeInterface::ATOM),
        };

        $weekday = $dt->format('l');

        return new ToolResult(
            true,
            json_encode(['formatted' => $rendered, 'weekday' => $weekday], JSON_THROW_ON_ERROR),
            ['formatted' => $rendered, 'weekday' => $weekday],
        );
    }
}
