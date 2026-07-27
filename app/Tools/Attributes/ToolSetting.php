<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Declares a single configurable setting on a Tool class.
 *
 * Used by:
 * - the operator-facing settings form (label, type, default, validation, required)
 * - the agent-level settings cascade (ToolConfigService reads / writes these)
 * - the LLM tool-definition payload (when `exposeToLlm` is true; see
 *   {@see \Spora\Services\ToolConfigSchemaInspector::getLlmToolSettings()})
 *
 * Examples
 * --------
 *
 * API key (operator-only, never sent to the LLM):
 *
 *   #[ToolSetting(
 *       key: 'api_key',
 *       label: 'API key',
 *       type: 'password',
 *       required: true,
 *   )]
 *
 * Allowlist the LLM is the consumer of (mirrors HandoverTool's
 * `allowed_target_agents`):
 *
 *   #[ToolSetting(
 *       key: 'allowed_skills',
 *       label: 'Allowed skills',
 *       type: 'multi-select',
 *       required: true,
 *       exposeToLlm: true,
 *       resolveAs: 'skill',  // see `resolveAs` below
 *   )]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolSetting
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        /** "text"|"password"|"select"|"toggle"|"textarea"|"multi-select" */
        public readonly string $type,
        public readonly string $description = '',
        public readonly mixed  $default     = null,
        public readonly bool   $required    = false,
        /** @var array<array-key, string> key => label pairs. Only used when type === "select". */
        public readonly array  $options = [],
        /** PCRE regex pattern for input validation, e.g. '/^[0-2](\.[0-9]+)?$/' for temperature. */
        public readonly string $validation = '',
        /**
         * Whether this setting's effective value should be included in the LLM tool definition.
         * Defaults to false because most settings are credentials/infrastructure.
         * Mark true for settings that directly affect what the LLM can do (e.g. allowed_recipients).
         */
        public readonly bool $exposeToLlm = false,
        /**
         * Optional URL the frontend multi-select renderer should fetch its
         * options from. Only consulted when `type === 'multi-select'`;
         * ignored otherwise. The fetched payload shape is
         * `[{value, label, ...}]` (caller-defined).
         *
         * Concrete example: the Skill tool's `allowed_skills` setting
         * declares `dataSource: '/skills?select=name,description'` so the
         * admin UI dropdown lists every available skill with its short
         * description as the option label. The path is intentionally
         * relative — the API client prepends `/api/v1`, so an absolute
         * `/api/v1/...` here would double up to `/api/v1/api/v1/skills`
         * and 404. See {@see \Spora\Tools\SkillTool::class} (around the
         * `allowed_skills` `#[ToolSetting]`) for the in-attribute comment
         * that records this trap.
         */
        public readonly ?string $dataSource = null,
        /**
         * How the multi-select value is stored and resolved for LLM exposure.
         * Only meaningful when `type === 'multi-select'`. Defaults to
         * `'agent'` for backwards compatibility with HandoverTool.
         *
         * - 'agent' — stored as `int[]`; LLM-facing values are resolved
         *   against the `Agent` Eloquent model to `"Name (#id)"` strings.
         * - 'skill' — stored as `string[]` of slugs; LLM-facing values
         *   are resolved against the `SkillScanner` to `"name: short
         *   description"` strings (description truncated to ~80 chars).
         * - 'raw'   — stored and surfaced as-is. Use when neither agent
         *   nor skill resolution fits the field's semantics.
         */
        public readonly string $resolveAs = 'agent',
    ) {}
}
