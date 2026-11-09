<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Spora\Metering\LlmUsage;
use Spora\Metering\MeteringContext;
use Spora\Metering\MeteringSubject;
use Spora\Metering\ToolUsage;
use Spora\Metering\UsageDecision;
use Spora\Metering\UsageMeterInterface;
use Throwable;

/**
 * A meter whose answers and failures the test controls.
 *
 * Records what it was asked and what it was told, so a test can assert both
 * the decision the core acted on and the order it acted in.
 */
final class StubUsageMeter implements UsageMeterInterface
{
    /** @var list<array{0: MeteringContext, 1: MeteringSubject}> */
    public array $evaluations = [];

    /** @var list<array{0: MeteringContext, 1: LlmUsage}> */
    public array $llmUsage = [];

    /** @var list<array{0: MeteringContext, 1: ToolUsage}> */
    public array $toolUsage = [];

    public ?UsageDecision $decision = null;
    public ?Throwable $evaluateThrows = null;
    public ?Throwable $recordThrows = null;

    public function evaluate(MeteringContext $context, MeteringSubject $subject): ?UsageDecision
    {
        $this->evaluations[] = [$context, $subject];

        if ($this->evaluateThrows !== null) {
            throw $this->evaluateThrows;
        }

        return $this->decision;
    }

    public function recordLlmUsage(MeteringContext $context, LlmUsage $usage): void
    {
        $this->llmUsage[] = [$context, $usage];

        if ($this->recordThrows !== null) {
            throw $this->recordThrows;
        }
    }

    public function recordToolUsage(MeteringContext $context, ToolUsage $usage): void
    {
        $this->toolUsage[] = [$context, $usage];

        if ($this->recordThrows !== null) {
            throw $this->recordThrows;
        }
    }
}
