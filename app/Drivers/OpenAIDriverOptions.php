<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * OpenAI-specific request behavior kept out of the driver constructor.
 *
 * The driver constructor sits at the seven-parameter cap, so the
 * settings that do not fit ride in this object.
 */
final class OpenAIDriverOptions
{
    /**
     * @param list<string> $configuredEffortLevels The levels the configuration
     *        names. Empty means the model's own set applies.
     */
    public function __construct(
        public readonly ?bool $supportsImageInput = null,
        public readonly array $configuredEffortLevels = [],

        /**
         * Null means "not configured" and behaves as enabled, so a row
         * persisted before the toggle shipped keeps working.
         */
        public readonly ?bool $supportsEffort = null,
    ) {}
}
