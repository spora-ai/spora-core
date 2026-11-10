<?php

declare(strict_types=1);

namespace Spora\Metering;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The only path the core uses to reach a {@see UsageMeterInterface}.
 *
 * A meter is plugin code running inside the agent loop, so it can throw — a
 * database hiccup, a bug, a price book that does not parse. Every call is
 * wrapped: the exception is logged at ERROR and the call proceeds as if the
 * meter had answered "allow". **A broken meter never stops work**; the one
 * thing that stops a run is a hard decision the meter returned, which is a
 * value rather than an exception and passes through untouched.
 */
final class MeteringGuard
{
    public function __construct(
        private readonly UsageMeterInterface $meter,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Whether a meter is installed at all.
     *
     * The core binds {@see NullUsageMeter} when no plugin provides one, and
     * a caller that would have to load a task row to build a
     * {@see MeteringContext} asks this first — so an install without a
     * metering plugin pays no query for the seam.
     */
    public function isActive(): bool
    {
        return !$this->meter instanceof NullUsageMeter;
    }

    public function evaluate(MeteringContext $context, MeteringSubject $subject): ?UsageDecision
    {
        try {
            return $this->meter->evaluate($context, $subject);
        } catch (Throwable $e) {
            $this->logFailure('evaluate', $context, $subject->kind(), $e);
            return null;
        }
    }

    public function recordLlmUsage(MeteringContext $context, LlmUsage $usage): void
    {
        try {
            $this->meter->recordLlmUsage($context, $usage);
        } catch (Throwable $e) {
            $this->logFailure('recordLlmUsage', $context, MeteringSubject::KIND_LLM, $e);
        }
    }

    public function recordToolUsage(MeteringContext $context, ToolUsage $usage): void
    {
        try {
            $this->meter->recordToolUsage($context, $usage);
        } catch (Throwable $e) {
            $this->logFailure('recordToolUsage', $context, MeteringSubject::KIND_TOOL, $e);
        }
    }

    private function logFailure(string $operation, MeteringContext $context, string $kind, Throwable $e): void
    {
        $this->logger?->error('Usage meter failed — the call proceeds', [
            'operation'       => $operation,
            'kind'            => $kind,
            'task_id'         => $context->taskId,
            'agent_id'        => $context->agentId,
            'principal_id'    => $context->principalId,
            'exception_class' => get_class($e),
            'message'         => $e->getMessage(),
        ]);
    }
}
