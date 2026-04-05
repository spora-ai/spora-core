<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Spora\Tools\Attributes\ToolSetting;

/**
 * Interface for LLM driver configurations.
 *
 * Each driver (OpenAICompatible, AnthropicCompatible, Mistral, etc.) implements
 * this interface and declares its settings via #[ToolSetting] PHP attributes.
 * The schema is discovered via reflection — no hardcoded field lists.
 *
 * Implementations MUST be registered in the container under 'llm_driver_classes'.
 */
interface LLMDriverConfigInterface
{
    /**
     * Snake_case identifier used to reference this driver.
     * e.g. "openai_compatible", "anthropic_compatible", "mistral".
     */
    public static function getName(): string;

    /**
     * Human-readable name for UI display.
     * e.g. "OpenAI Compatible", "Anthropic Compatible".
     */
    public static function getDisplayName(): string;

    /**
     * Returns the settings schema as #[ToolSetting] attribute instances.
     * Discovered via ReflectionClass::getAttributes(ToolSetting::class).
     *
     * @return list<ToolSetting>
     */
    public static function getSettingsSchema(): array;

    /**
     * List of tool class names available by default for this driver.
     * Agents may override this list.
     *
     * @return list<class-string>
     */
    public static function getDefaultTools(): array;
}
