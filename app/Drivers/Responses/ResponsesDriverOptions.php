<?php

declare(strict_types=1);

namespace Spora\Drivers\Responses;

/**
 * Responses-specific request behaviour kept out of the driver constructor.
 *
 * The driver constructor sits at the seven-parameter cap, so the
 * settings that do not fit ride in this object.
 */
final class ResponsesDriverOptions
{
    public function __construct(
        public readonly ?bool $supportsImageInput = null,

        /**
         * Whether the provider keeps the response on its own servers.
         *
         * The Responses API stores a response as a retrievable object for
         * at least 30 days unless it is told not to, so the default is
         * off: Spora replays the encrypted reasoning itself. Turning it
         * off does not keep the prompts and responses out of OpenAI's
         * abuse-monitoring logs — only an approved Zero Data Retention
         * or Modified Abuse Monitoring control does that.
         */
        public readonly bool $store = false,
    ) {}
}
