<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Tools\ValueObjects\ToolResult;

/**
 * Validates the slim `create_agent` payload.
 *
 * The agent-template.schema.json shape (id, name, version, agent{},
 * required_plugins[]) is reserved for the operator-upload endpoint at
 * POST /api/v1/agent-templates/import — driving the operator flow
 * through an LLM-facing call was the root cause of the task #46
 * failures (too many nested keys, too easy to put `name` inside
 * `agent{}` or send `required_plugins` as a bare value).
 *
 * The static `unwrapSingleItemArray()` is shared with `ConfigurePlanner`
 * (defensive unwrap of the OpenAI assistant `{item: [...]}` quirk).
 */
final class SlimPayloadValidator
{
    private const DESCRIPTION_MAX_LENGTH   = 2000;
    private const NAME_MAX_LENGTH          = 200;
    private const MAX_STEPS_MIN            = 1;
    private const MAX_STEPS_MAX            = 100;

    private const KNOWN_SLIM_KEYS = [
        'name',
        'description',
        'system_prompt',
        'max_steps',
        'allow_followup',
        'retry_after_minutes',
        'max_retries',
        'agent',
        'tools',
        'required_plugins',
    ];

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    public function validateCreateAgentPayload(array $arguments): array|ToolResult
    {
        $raw = $arguments['payload'] ?? null;
        $error = $this->shapeError($raw);
        if ($error !== null) {
            return $error;
        }

        return $this->buildValidatedCreateAgent($raw);
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>|ToolResult
     */
    private function buildValidatedCreateAgent(array $raw): array|ToolResult
    {
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        if ($name === '' || strlen($name) > self::NAME_MAX_LENGTH) {
            return ToolResult::fail(
                'create_agent: `name` is required (1..' . self::NAME_MAX_LENGTH . ' chars). '
                . 'Send `name: "..."` at the top level of the payload, not inside `agent{}`.',
            );
        }
        if (is_string($raw['description'] ?? null)
            && mb_strlen($raw['description']) > self::DESCRIPTION_MAX_LENGTH
        ) {
            return ToolResult::fail(
                'create_agent: `description` must be ' . self::DESCRIPTION_MAX_LENGTH . ' chars or fewer. '
                . 'Send a shorter `description` string.',
            );
        }

        return [
            'name'                 => $name,
            'description'          => is_string($raw['description'] ?? null) ? $raw['description'] : null,
            'system_prompt'        => is_string($raw['system_prompt'] ?? null) ? $raw['system_prompt'] : null,
            'llm_driver_config_id' => null,
            'max_steps'            => (int) ($raw['max_steps'] ?? 10),
            'allow_followup'       => (bool) ($raw['allow_followup'] ?? true),
            'retry_after_minutes'  => (int) ($raw['retry_after_minutes'] ?? 0),
            'max_retries'          => (int) ($raw['max_retries'] ?? 0),
        ];
    }

    /**
     * Validate the slim-payload option keys. Each rule emits a literal
     * "send X instead" example so the LLM can copy-paste the fix rather
     * than guess.
     *
     * @param  mixed $raw
     */
    public function createAgentPayloadErrors(mixed $raw): ?ToolResult
    {
        if (!is_array($raw)) {
            return ToolResult::fail('create_agent: `payload` object is required.');
        }
        $rules = [
            $this->validateMaxSteps($raw),
            $this->validateAllowFollowup($raw),
            $this->validateRetryAfterMinutes($raw),
            $this->validateMaxRetries($raw),
            $this->validateRequiredPlugins($raw),
            $this->validateAdditionalProperties($raw),
        ];
        foreach ($rules as $failure) {
            if ($failure !== null) {
                return $failure;
            }
        }
        return null;
    }

    /**
     * @param  mixed $value
     */
    public function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if ($value === []) {
            return true;
        }
        return self::allStrings($value);
    }

    /**
     * Defensive unwrap for the OpenAI assistant tool-call channel,
     * which can serialise a single-element array as `{"item": [...]}`
     * instead of `[...]`. Without this unwrap the LLM sees a
     * confusing "must be an array" error on payloads that clearly
     * were arrays.
     *
     * Fires only on the unambiguous wrap shape: non-list assoc with
     * one key called `item` whose value is itself an array. Anything
     * else (multi-key objects, regular lists, scalars, `{item:
     * "scalar"}`) passes through untouched so legitimate payloads
     * survive.
     *
     * @param  mixed        $value
     * @return mixed
     */
    public static function unwrapSingleItemArray(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        if ($keys === ['item'] && is_array($value['item'] ?? null)) {
            return $value['item'];
        }
        return $value;
    }

    private function validateMaxSteps(array $raw): ?ToolResult
    {
        if (!array_key_exists('max_steps', $raw)) {
            return null;
        }
        $value = $raw['max_steps'];
        if (!is_int($value) || $value < self::MAX_STEPS_MIN || $value > self::MAX_STEPS_MAX) {
            return ToolResult::fail(
                'create_agent: `max_steps` must be an integer in '
                . self::MAX_STEPS_MIN . '..' . self::MAX_STEPS_MAX . '. '
                . 'Send `"max_steps": 10` (note: not a string).',
            );
        }
        return null;
    }

    private function validateAllowFollowup(array $raw): ?ToolResult
    {
        if (!array_key_exists('allow_followup', $raw)) {
            return null;
        }
        if (!is_bool($raw['allow_followup'])) {
            return ToolResult::fail(
                'create_agent: `allow_followup` must be a boolean. '
                . 'Send `"allow_followup": true`, not the string `"true"`.',
            );
        }
        return null;
    }

    private function validateRetryAfterMinutes(array $raw): ?ToolResult
    {
        if (!array_key_exists('retry_after_minutes', $raw)) {
            return null;
        }
        $value = $raw['retry_after_minutes'];
        if (!is_int($value) || $value < 0) {
            return ToolResult::fail(
                'create_agent: `retry_after_minutes` must be a non-negative integer.',
            );
        }
        return null;
    }

    private function validateMaxRetries(array $raw): ?ToolResult
    {
        if (!array_key_exists('max_retries', $raw)) {
            return null;
        }
        $value = $raw['max_retries'];
        if (!is_int($value) || $value < 0) {
            return ToolResult::fail(
                'create_agent: `max_retries` must be a non-negative integer.',
            );
        }
        return null;
    }

    private function validateRequiredPlugins(array $raw): ?ToolResult
    {
        if (!array_key_exists('required_plugins', $raw)) {
            return null;
        }
        return $this->requiredPluginsError();
    }

    private function validateAdditionalProperties(array $raw): ?ToolResult
    {
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, self::KNOWN_SLIM_KEYS, true)) {
                return ToolResult::fail(
                    'create_agent: `' . $key . '` is not a known slim-payload key. '
                    . 'Allowed top-level keys: ' . implode(', ', self::KNOWN_SLIM_KEYS) . '.',
                );
            }
        }
        return null;
    }

    private function requiredPluginsError(): ToolResult
    {
        // `required_plugins` is part of the operator-upload
        // agent-template schema but not the slim payload — the
        // LLM-facing `create_agent` won't store it on the agent
        // row. Plugins install out-of-band via the dashboard.
        return ToolResult::fail(
            'create_agent: `required_plugins` is reserved for the operator-upload endpoint '
            . '(POST /api/v1/agent-templates/import) and is not stored on agents created via '
            . 'the LLM-facing slim payload. Install plugins via the dashboard or the '
            . '`spora plugin install` CLI before creating the agent.',
        );
    }

    /**
     * Short-circuit the slim-payload shape checks so the orchestrator
     * function only needs one return.
     */
    private function shapeError(mixed $raw): ?ToolResult
    {
        if (!is_array($raw) || $raw === []) {
            return ToolResult::fail('create_agent: `payload` object is required.');
        }

        $nestedError = $this->nestedNamedBlockError($raw);
        return $nestedError ?? $this->createAgentPayloadErrors($raw);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function nestedNamedBlockError(array $raw): ?ToolResult
    {
        if (isset($raw['agent']) && is_array($raw['agent'])) {
            return ToolResult::fail(
                'create_agent: send a slim payload (name, description, system_prompt, ...) — '
                . 'do NOT wrap fields in an `agent{}` block. See the agent-creation skill '
                . '(skill action: read, name: agent-creation, filename: SKILL.md).',
            );
        }
        if (isset($raw['tools']) && $raw['tools'] !== []) {
            return ToolResult::fail(
                'create_agent: `tools[]` is no longer accepted here. Create the agent first, '
                . 'then call `configure_tools(agent_id: N, tools: [...])` to apply a toolset. '
                . 'See the agent-creation skill.',
            );
        }
        return null;
    }

    /**
     * @param  array<int|string, mixed> $value
     */
    private static function allStrings(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }
        return true;
    }
}
