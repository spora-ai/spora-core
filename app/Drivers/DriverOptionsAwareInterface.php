<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The optional capability of a driver that needs more than the common
 * constructor arguments to be built — driver-specific options carried
 * in a settings blob.
 *
 * The Anthropic branch in {@see DriverFactory} used to be a hardcoded
 * class comparison; a capability question is generic and a class
 * comparison is not.
 */
interface DriverOptionsAwareInterface
{
    /**
     * Build the driver from the common constructor arguments and the
     * decoded driver settings.
     *
     * @param array<string, mixed> $args     apiKey, model, baseUrl, httpClient,
     *                                        logger, timeout, supportsImageInput
     * @param array<string, mixed> $settings the decoded driver settings blob
     */
    public static function makeFromSettings(array $args, array $settings): LLMDriverInterface;
}
