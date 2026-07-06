<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Declares a UI-configurable setting for a tool.
 *
 * The `key` is the bare field name (e.g. `api_key`, `http_timeout`) — it is
 * scoped to the declaring tool class and resolved per-tool by
 * `ToolConfigService::getEffectiveSettings(toolClass, ...)`. Two tools may
 * declare a setting with the same key name without colliding.
 *
 * Usage:
 *   #[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', required: true)]
 *   #[ToolSetting(key: 'max_results', label: 'Max Results', type: 'text', default: '10')]
 *   #[ToolSetting(key: 'allowed_agents', label: 'Allowed Agents', type: 'multi-select',
 *                 required: true, exposeToLlm: true)]
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
    ) {}
}
