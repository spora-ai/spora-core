<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;

/**
 * A surrogate configuration class to allow the Vue frontend to securely configure
 * API keys via the 'ToolConfigService' database encryption mechanism.
 */
#[Tool(name: 'LLM Providers', description: 'Settings for LLM API keys.')]
#[ToolSetting(
    key: 'openai_api_key',
    label: 'OpenAI API Key',
    type: 'password',
    description: 'API key for OpenAI-compatible endpoints.',
    scope: 'agent',
)]
#[ToolSetting(
    key: 'anthropic_api_key',
    label: 'Anthropic API Key',
    type: 'password',
    description: 'API key for Anthropic Claude.',
    scope: 'agent',
)]
final class LLMConfiguration
{
    // Pure configuration class.
}
