<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Metering\Exceptions\UsageLimitExceededException;
use Spora\Metering\MeteringContextFactory;
use Spora\Metering\MeteringGuard;
use Spora\Metering\ToolSubject;
use Spora\Metering\ToolUsage;
use Spora\Tools\ToolInterface;
use Throwable;

/**
 * The metering seam's view of the tool path.
 *
 * The gate is asked whether a tool call may proceed, and told what the call
 * cost afterwards. Both halves share one scope: the answer and the record
 * have to describe the same call, and building the subject twice could
 * resolve it to two different answers.
 *
 * The scope is null when there is no meter to talk to, which keeps the seam
 * invisible on an install without a metering plugin: no query, no operation
 * resolution, no call the agent turn did not already make.
 */
final class ToolUsageGate
{
    public function __construct(
        private readonly MeteringGuard $guard,
        private readonly MeteringContextFactory $contextFactory,
        private readonly ToolOperationResolver $toolOperations,
    ) {}

    public function scopeFor(
        int $taskId,
        string $toolName,
        string $toolClass,
        ToolInterface $toolInstance,
        array $arguments,
        int $agentId,
    ): ?ToolUsageScope {
        // Building the context costs a query, and resolving the operation
        // reads the agent's operation overrides — so neither runs unless a
        // meter is installed to receive the answer.
        if (!$this->guard->isActive()) {
            return null;
        }

        $context = $this->contextFactory->forTaskId($taskId);
        if ($context === null) {
            // The task was deleted mid-call.
            return null;
        }

        return new ToolUsageScope(
            context: $context,
            toolName: $toolName,
            toolClass: $toolClass,
            operation: $this->resolveOperation($toolInstance, $arguments, $agentId),
        );
    }

    /**
     * @throws UsageLimitExceededException  The meter returned a hard decision.
     */
    public function enforce(?ToolUsageScope $scope): void
    {
        if ($scope === null) {
            return;
        }

        $decision = $this->guard->evaluate(
            $scope->context,
            new ToolSubject($scope->toolName, $scope->toolClass, $scope->operation),
        );

        if ($decision !== null && $decision->isHard()) {
            throw new UsageLimitExceededException($decision);
        }
    }

    public function record(?ToolUsageScope $scope, bool $succeeded): void
    {
        if ($scope === null) {
            return;
        }

        $this->guard->recordToolUsage(
            $scope->context,
            new ToolUsage($scope->toolName, $scope->toolClass, $scope->operation, $succeeded),
        );
    }

    /**
     * The operation a call dispatches to, or null when it cannot be resolved.
     *
     * A tool that declares no operation makes the resolver throw. That is a
     * property of the tool, not a usage question, so the subject names no
     * operation.
     */
    private function resolveOperation(ToolInterface $toolInstance, array $arguments, int $agentId): ?string
    {
        try {
            return $this->toolOperations->resolveOperationName($toolInstance, $arguments, $agentId);
        } catch (Throwable) {
            return null;
        }
    }
}
