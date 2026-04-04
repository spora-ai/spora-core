<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;

/**
 * A surrogate configuration class to allow the Vue frontend to securely configure
 * API keys via the 'ToolConfigService' database encryption mechanism.
 *
 * This is the ONLY standalone configuration class — it has no corresponding
 * agent tool. It feeds the DriverFactory, not the Orchestrator tool pipeline.
 */
#[Tool(name: 'LLM Providers', description: 'Settings for LLM API keys.')]
#[ToolSetting(
    key: 'core.openai.api_key',
    label: 'OpenAI API Key',
    type: 'password',
    description: 'API key for OpenAI-compatible endpoints.',
    scope: 'agent',
)]
#[ToolSetting(
    key: 'core.anthropic.api_key',
    label: 'Anthropic API Key',
    type: 'password',
    description: 'API key for Anthropic Claude.',
    scope: 'agent',
)]
final class LLMConfiguration
{
    // Pure configuration class.
}
