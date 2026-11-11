<?php

declare(strict_types=1);

namespace Spora\Metering;

/**
 * A provider request about to leave.
 *
 * `provider` is the driver's protocol name (`openai_compatible`,
 * `anthropic`) and `model` the configured model — the pair a price is
 * keyed on.
 */
final readonly class LlmSubject implements MeteringSubject
{
    public function __construct(
        public string $provider,
        public string $model,
    ) {}

    public function kind(): string
    {
        return self::KIND_LLM;
    }
}
