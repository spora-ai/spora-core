<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Tools\LlmScalarCoercion;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Validates the `update_agent` patch.
 *
 * `update_agent` had no type validation at all: the patch went straight to
 * `AgentService::updateAgentByAgentId`, which filters the *column set* but
 * not the *value types*. A provider that emits scalars as strings therefore
 * wrote the literal string `'false'` into a boolean column, which reads back
 * as true — so asking an agent to be unarchived archived it, and the response
 * confirmed it. Stringified numbers bypassed the bounds `create_agent`
 * enforces, so `max_steps: "999"` and `retry_after_minutes: "-5"` were
 * accepted verbatim.
 *
 * So this is not leniency for its own sake. It is the same rule the create
 * path already applies, plus the coercion the provider forces on us, applied
 * in the one order that is safe: **coerce first, range-check second.** A
 * `"999"` becomes `999` and is then refused for being out of range, rather
 * than being accepted because it arrived quoted.
 *
 * Scope is the fields the `agent` parameter documents. Keys outside that
 * set are forwarded untouched for `AgentService` to filter against
 * `EDITABLE_AGENT_FIELDS`, which is where the allowlist lives.
 */
final class AgentPatchValidator
{
    use LlmScalarCoercion;

    private const MAX_STEPS_MIN   = 1;
    private const MAX_STEPS_MAX   = 100;
    private const NAME_MAX_LENGTH = 200;
    private const DESC_MAX_LENGTH = 2000;

    /** Columns this surface may write, and nothing else. */
    private const PATCHABLE = [
        'name'                 => 'string',
        'description'          => '?string',
        'system_prompt'        => '?string',
        'max_steps'            => 'int:1..100',
        'allow_followup'       => 'bool',
        'retry_after_minutes'  => 'int:0..',
        'max_retries'          => 'int:0..',
        'is_pinned'            => 'bool',
        'is_archived'          => 'bool',
    ];

    private const ERR_PREFIX = 'update_agent: ';

    /**
     * Coerce and range-check the fields this surface documents, forwarding
     * everything else untouched.
     *
     * Unknown keys are deliberately not refused here. The allowlist belongs
     * to `AgentService::EDITABLE_AGENT_FIELDS`, and the tool layer forwards
     * so the service can enforce it — a boundary `AgentToolTest` pins. This
     * validator's job is the *value* types, which the service never checked
     * and which were the actual defect.
     *
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>|ToolResult The canonical patch, or the first refusal.
     */
    public function normalise(array $raw): array|ToolResult
    {
        $patch = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            $rule = self::PATCHABLE[$key] ?? null;
            if ($rule === null) {
                $patch[$key] = $value;
                continue;
            }
            if ($value === null && str_starts_with($rule, '?')) {
                $patch[$key] = null;
                continue;
            }
            $normalised = $this->coerce($key, $rule, $value);
            if ($normalised instanceof ToolResult) {
                return $normalised;
            }
            $patch[$key] = $normalised;
        }
        return $patch;
    }

    /**
     * @return mixed|ToolResult
     */
    private function coerce(string $key, string $rule, mixed $value)
    {
        return match (true) {
            $rule === 'bool'      => $this->boolean($key, $value),
            $rule === '?string'   => $this->nullableString($key, $value),
            $rule === 'string'    => $this->text($key, $value, self::NAME_MAX_LENGTH, 'a non-empty string'),
            $rule === 'int:1..100' => $this->boundedInt($key, $value, self::MAX_STEPS_MIN, self::MAX_STEPS_MAX),
            $rule === 'int:0..'   => $this->boundedInt($key, $value, 0, PHP_INT_MAX),
            default               => ToolResult::fail(self::ERR_PREFIX . "unhandled rule for '{$key}'."),
        };
    }

    /**
     * @return bool|ToolResult
     */
    private function boolean(string $key, mixed $value)
    {
        $coerced = $this->coerceBool($value);
        if ($coerced === null) {
            return $this->badType($key, 'a boolean', $value, 'true');
        }
        return $coerced;
    }

    /**
     * @return string|ToolResult
     */
    private function nullableString(string $key, mixed $value)
    {
        if (!is_string($value)) {
            return $this->badType($key, 'a string', $value, 'a text value');
        }
        if (mb_strlen($value) > self::DESC_MAX_LENGTH) {
            return ToolResult::fail(self::ERR_PREFIX . sprintf(
                '`%s` must be %d chars or fewer.',
                $key,
                self::DESC_MAX_LENGTH,
            ));
        }
        return $value;
    }

    /**
     * @return string|ToolResult
     */
    private function text(string $key, mixed $value, int $max, string $expectation)
    {
        if (!is_string($value) || trim($value) === '') {
            return $this->badType($key, $expectation, $value, '"a name"');
        }
        if (mb_strlen($value) > $max) {
            return ToolResult::fail(self::ERR_PREFIX . sprintf(
                '`%s` must be %d chars or fewer.',
                $key,
                $max,
            ));
        }
        return $value;
    }

    /**
     * @return int|ToolResult
     */
    private function boundedInt(string $key, mixed $value, int $min, int $max)
    {
        $coerced = $min > 0 ? $this->coercePositiveInt($value) : $this->coerceNonNegativeInt($value);
        if ($coerced === null || $coerced < $min || $coerced > $max) {
            return ToolResult::fail(self::ERR_PREFIX . sprintf(
                '`%s` must be an integer in %d..%s, got %s. Send a whole number — '
                . 'a quoted one is read as the number, then range-checked.',
                $key,
                $min,
                $max === PHP_INT_MAX ? 'PHP_INT_MAX' : (string) $max,
                $this->describeValue($value),
            ));
        }
        return $coerced;
    }

    private function badType(string $key, string $expectation, mixed $value, string $example): ToolResult
    {
        return ToolResult::fail(self::ERR_PREFIX . sprintf(
            '`%s` must be %s, got %s. Send %s.',
            $key,
            $expectation,
            $this->describeValue($value),
            $example,
        ));
    }
}
