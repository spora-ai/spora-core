<?php

declare(strict_types=1);

namespace Spora\Tools\Traits;

use ReflectionClass;
use RuntimeException;
use Spora\Tools\Attributes\ToolOperation;

/**
 * Opt-in trait for tools that declare multiple operations.
 *
 * Provides per-operation resolution helpers used by the Orchestrator
 * and the AgentController to determine enabled/approval state.
 */
trait HasOperations
{
    /**
     * Return the operation name by reading the discriminator key from arguments.
     * Falls back to 'default' when the discriminator key is absent.
     *
     * @param  array<string, mixed> $arguments
     */
    public function getOperationName(array $arguments): string
    {
        $ref = new ReflectionClass($this);
        $operations = $ref->getAttributes(ToolOperation::class);

        if ($operations === []) {
            throw new RuntimeException(
                'getOperationName() called on a tool that has no #[ToolOperation] attributes. '
                . 'Either add #[ToolOperation] declarations or do not use the HasOperations trait.'
            );
        }

        /** @var ToolOperation $first */
        $first = $operations[0]->newInstance();

        $discriminatorValue = $arguments[$first->discriminatorKey] ?? null;

        // If the discriminator key is absent, fall back to the first declared operation.
        // This handles LLM providers that don't echo the tool name as an argument field.
        if ($discriminatorValue === null || $discriminatorValue === '') {
            return $first->name;
        }

        return (string) $discriminatorValue;
    }

    /**
     * Return the ToolOperation matching the given name, or null if not found.
     */
    public function getOperation(string $operationName): ?ToolOperation
    {
        $ref = new ReflectionClass($this);
        foreach ($ref->getAttributes(ToolOperation::class) as $attr) {
            $op = $attr->newInstance();
            if ($op->name === $operationName) {
                return $op;
            }
        }
        return null;
    }

    /**
     * Return a human-readable description for the given operation name.
     * Falls back to the operation name itself if no description is set.
     */
    public function getOperationDescription(string $operationName): string
    {
        $op = $this->getOperation($operationName);
        return $op?->description ?: $operationName;
    }

    /**
     * Return whether the given operation is enabled by default.
     */
    public function isEnabledByDefault(string $operationName): bool
    {
        $op = $this->getOperation($operationName);
        return $op->enabledByDefault ?? true;
    }

    /**
     * Return whether the given operation requires approval by default.
     */
    public function requiresApprovalByDefault(string $operationName): bool
    {
        $op = $this->getOperation($operationName);
        return $op->requiresApprovalByDefault ?? true;
    }

    /**
     * Return all declared #[ToolOperation] instances on this class.
     *
     * @return ToolOperation[]
     */
    public function getOperations(): array
    {
        $ref = new ReflectionClass($this);
        return array_map(
            fn (\ReflectionAttribute $attr) => $attr->newInstance(),
            $ref->getAttributes(ToolOperation::class),
        );
    }
}
