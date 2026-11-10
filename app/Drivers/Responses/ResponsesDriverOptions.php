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
         * off.
         *
         * The toggle does not decide who replays the reasoning: Spora
         * sends the encrypted content whenever it has one, and the
         * toggle only decides what happens to an item that carries none
         * — dropped from the replay when off, sent by id alone when on,
         * which only the provider can resolve if it kept the item.
         *
         * Turning it off does not keep the prompts and responses out of
         * OpenAI's abuse-monitoring logs — only a Zero Data Retention or
         * Modified Abuse Monitoring control on the project the API key
         * belongs to does that. Under Zero Data Retention the provider
         * treats the store parameter as false whatever the toggle says,
         * so there is no stored response for a reasoning id to resolve
         * against: the toggle changes nothing on the provider's side,
         * and its id-only fallback would have nothing to resolve.
         */
        public readonly bool $store = false,
    ) {}
}
