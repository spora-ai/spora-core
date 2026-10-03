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
 * Scope is the fields the `agent` parameter documents, plus a refusal for the
 * handful of service-writable columns this surface does not own. Anything else
 * is forwarded untouched for `AgentService` to filter against
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
        'name'                 => self::T_NAME,
        'description'          => self::T_TEXT,
        'system_prompt'        => self::T_TEXT,
        'max_steps'            => self::T_STEPS,
        'allow_followup'       => self::T_BOOL,
        'retry_after_minutes'  => self::T_NON_NEGATIVE,
        'max_retries'          => self::T_NON_NEGATIVE,
        'is_pinned'            => self::T_BOOL,
        'is_archived'          => self::T_BOOL,
    ];

    private const T_NAME        = 'name';
    private const T_TEXT        = 'text';
    private const T_BOOL        = 'bool';
    private const T_STEPS       = 'steps';
    private const T_NON_NEGATIVE = 'non-negative';

    /**
     * Fields whose shape accepts JSON `null` to clear the value, as the operator
     * panel sends. A null on any other field is a type error, not a clear —
     * `is_archived: null` cannot mean "unarchive" and must not be read as false.
     */
    private const NULLABLE = [
        'description'   => true,
        'system_prompt' => true,
    ];

    /**
     * Columns the service allowlist permits but this surface does not own.
     *
     * `AgentService::EDITABLE_AGENT_FIELDS` is the anti-escalation list — what
     * the tool must not reach past — and it necessarily includes the columns
     * an operator legitimately edits through the dashboard. Two of them also
     * decide *which driver configuration an agent runs on*, which is not a
     * content edit: an LLM that can write them can repoint an agent at a
     * different model, provider or credential set, and nothing on this surface
     * lets it read the valid ids first, so it would be guessing at a value
     * with real consequences.
     *
     * Refused rather than dropped, because a silent drop reads to the model as
     * "that landed" — the same failure shape as the dead override row. Keys
     * outside both this list and `PATCHABLE` are still forwarded untouched for
     * the service to filter, which is the boundary `AgentToolTest` pins.
     */
    private const NOT_OURS = [
        'llm_driver_config_id'             => 'which LLM configuration the agent runs on',
        'speech_driver_config_id'          => 'which speech configuration the agent uses',
        'voice_message_retention_count'    => 'the voice-message retention window',
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
                $notOurs = self::NOT_OURS[$key] ?? null;
                if ($notOurs !== null) {
                    return ToolResult::fail(self::ERR_PREFIX . sprintf(
                        "'%s' is not writable through this tool — it decides %s, and an operator sets it. "
                        . 'Removing the key writes nothing at all, so the whole patch is refused.',
                        $key,
                        $notOurs,
                    ));
                }
                $patch[$key] = $value;
                continue;
            }
            if ($value === null && isset(self::NULLABLE[$key])) {
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
        return match ($rule) {
            self::T_BOOL         => $this->boolean($key, $value),
            self::T_TEXT         => $this->nullableString($key, $value),
            self::T_NAME         => $this->text($key, $value, self::NAME_MAX_LENGTH, 'a non-empty string'),
            self::T_STEPS        => $this->boundedInt($key, $value, self::MAX_STEPS_MIN, self::MAX_STEPS_MAX),
            self::T_NON_NEGATIVE => $this->boundedInt($key, $value, 0, PHP_INT_MAX),
            default              => ToolResult::fail(self::ERR_PREFIX . "unhandled rule for '{$key}'."),
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
