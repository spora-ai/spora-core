<?php

declare(strict_types=1);

namespace Spora\Metering;

/**
 * The seam a plugin implements to meter agent work and to veto it.
 *
 * The core owns the two moments that matter — the provider request is about
 * to leave, and the tool is about to run — and asks the meter before each
 * one. A plugin that answers `null` lets the work proceed; a hard decision
 * stops the task.
 *
 * The default binding is {@see NullUsageMeter}, so a Spora install with no
 * metering plugin behaves exactly as it did before the seam existed. A
 * plugin replaces the binding from a `ContainerBuildingEvent` subscriber:
 *
 * ```php
 * $event->builder()->addDefinitions([
 *     UsageMeterInterface::class => \DI\autowire(UsageMeter::class),
 * ]);
 * ```
 *
 * Implementations are third-party code in the hot path, so the core calls
 * them through {@see MeteringGuard}: a meter that throws is logged and
 * ignored. Only a returned hard decision stops a run.
 */
interface UsageMeterInterface
{
    /**
     * Consulted before every provider request and every tool execution.
     *
     * @return UsageDecision|null  Null allows the call. A hard decision
     *                              stops the task; a soft decision lets the
     *                              call proceed and is not recorded here —
     *                              a meter that wants a soft breach to be
     *                              visible writes it down itself.
     */
    public function evaluate(MeteringContext $context, MeteringSubject $subject): ?UsageDecision;

    /** Called after a provider request returns, with the tokens it consumed. */
    public function recordLlmUsage(MeteringContext $context, LlmUsage $usage): void;

    /** Called after a tool execution returns. */
    public function recordToolUsage(MeteringContext $context, ToolUsage $usage): void;
}
