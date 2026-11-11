<?php

declare(strict_types=1);

namespace Spora\Metering;

/**
 * What is about to be metered: a provider request or a tool execution.
 */
interface MeteringSubject
{
    public const KIND_LLM = 'llm';
    public const KIND_TOOL = 'tool';

    public function kind(): string;
}
