<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Models\AgentToolOperationOverride;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Traits\HasOperations;

/**
 * Which operation a tool call dispatches to, and whether that operation is
 * switched on for the agent.
 *
 * `HasOperations::getOperationName()` falls back to the FIRST declared
 * operation, but `OperationSchemaFilter` drops the discriminator from
 * `required[]` when the agent has exactly one operation enabled — so the
 * runtime has to resolve the operation the way the schema does. Otherwise an
 * agent whose only enabled operation is not the first declared one answers
 * *"Operation '<first>' is disabled for this agent."* to a call its schema
 * told the model was fine.
 */
final class ToolOperationResolver
{
    /**
     * The operation a call dispatches to.
     *
     * The discriminator the model sent wins. Without it, the single
     * operation enabled for the agent is unambiguous; with several enabled
     * the model had to name one, so the first declared operation is the
     * last resort.
     */
    public function resolveOperationName(object $toolInstance, array $arguments, int $agentId): string
    {
        $operations = $toolInstance->getOperations();
        if ($operations === []) {
            // A tool that uses the trait without declaring an operation is
            // broken; getOperationName() says so in the terms the caller
            // expects.
            return $toolInstance->getOperationName($arguments);
        }

        $discriminatorKey = $operations[0]->discriminatorKey;
        if (isset($arguments[$discriminatorKey]) && $arguments[$discriminatorKey] !== '') {
            return (string) $arguments[$discriminatorKey];
        }

        $enabled = $this->enabledOperationNames($toolInstance, $agentId);
        $only = count($enabled) === 1 ? $enabled[0] : null;

        return $only ?? $toolInstance->getOperationName($arguments);
    }

    /**
     * The call's arguments with the discriminator filled in, when the model
     * left it out.
     *
     * The tool dispatches on `getOperationName()`, so without this the call
     * would be validated against one operation and executed against another.
     */
    public function withResolvedDiscriminator(object $toolInstance, array $arguments, int $agentId): array
    {
        $operations = $this->declaredOperations($toolInstance);
        $discriminatorKey = count($operations) >= 2 ? $operations[0]->discriminatorKey : null;

        if ($discriminatorKey === null || (isset($arguments[$discriminatorKey]) && $arguments[$discriminatorKey] !== '')) {
            return $arguments;
        }

        $arguments[$discriminatorKey] = $this->resolveOperationName($toolInstance, $arguments, $agentId);

        return $arguments;
    }

    /**
     * Whether the agent has the operation switched on.
     *
     * The agent's override row, then the operation's attribute default. The
     * row is the configuration and is returned verbatim — the attribute is
     * only the activation-time default.
     */
    public function isEnabled(object $toolInstance, string $operationName, int $agentId): bool
    {
        $override = $this->override($toolInstance, $operationName, $agentId);

        if ($override !== null) {
            $raw = $override->getRawOriginal('enabled');
            if ($raw !== null) {
                return (bool) $raw;
            }
        }

        return $toolInstance->isEnabledByDefault($operationName);
    }

    /**
     * @return list<string>
     */
    private function enabledOperationNames(object $toolInstance, int $agentId): array
    {
        $names = array_map(
            static fn(ToolOperation $op): string => $op->name,
            $this->declaredOperations($toolInstance),
        );

        return array_values(array_filter(
            $names,
            fn(string $name): bool => $this->isEnabled($toolInstance, $name, $agentId),
        ));
    }

    /**
     * @return list<ToolOperation>
     */
    private function declaredOperations(object $toolInstance): array
    {
        if (!in_array(HasOperations::class, class_uses_recursive(get_class($toolInstance)), true)) {
            return [];
        }

        return $toolInstance->getOperations();
    }

    private function override(object $toolInstance, string $operationName, int $agentId): ?AgentToolOperationOverride
    {
        return AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', get_class($toolInstance))
            ->where('operation', $operationName)
            ->first();
    }
}
