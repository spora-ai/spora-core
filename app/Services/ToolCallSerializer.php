<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Models\ToolCall;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\ToolInterface;

/**
 * Single source of truth for the JSON shape of a ToolCall sent to the
 * frontend — both via the REST task resource (TaskService::taskResource)
 * and via the Mercure live-update stream (Orchestrator::publishIntermediateState).
 *
 * Keeping both serialization paths through this class guarantees the frontend
 * sees identical fields on a tool call regardless of the transport, and that
 * any future additions to the payload (e.g. the new `parameter_schema` field)
 * land in one place.
 *
 * The `parameter_schema` field is derived at serialization time from the live
 * tool instance — no DB column required. If the tool class can't be resolved
 * (e.g. a plugin tool whose class was uninstalled after the call was made),
 * the field is omitted gracefully.
 */
final class ToolCallSerializer
{
    /**
     * @param list<ToolInterface> $toolInstances The same tool instances the Orchestrator was constructed with.
     */
    public function __construct(
        private readonly array $toolInstances = [],
    ) {}

    /**
     * Convert a ToolCall model to its API/Mercure JSON shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(ToolCall $tc): array
    {
        $payload = [
            'id'                    => $tc->id,
            'provider_call_id'      => $tc->provider_call_id,
            'tool_name'             => $tc->tool_name,
            'tool_type'             => $tc->tool_type,
            'status'                => $tc->status,
            'proposed_arguments'    => $tc->proposed_arguments,
            'approved_arguments'    => $tc->approved_arguments,
            'human_description'     => $tc->human_description,
            'operation'             => $tc->operation,
            'operation_description' => $tc->operation_description,
            'result_content'        => $tc->result_content,
            'result_data'           => $tc->result_data,
            'executed_at'           => $tc->executed_at?->toIso8601String(),
        ];

        $schema = $this->resolveParameterSchema($tc->tool_class);
        if ($schema !== null) {
            $payload['parameter_schema'] = $schema;
        }

        return $payload;
    }

    /**
     * @return array{type: string, properties: object|array<string, mixed>, required: list<string>}|null
     */
    private function resolveParameterSchema(?string $toolClass): ?array
    {
        if ($toolClass === null || $toolClass === '') {
            return null;
        }

        foreach ($this->toolInstances as $instance) {
            if ($instance::class === $toolClass) {
                return $instance->getParametersSchema();
            }
        }

        // Fallback: instantiate via reflection if the class still exists but
        // wasn't registered (defensive — keeps history rendering robust when
        // a plugin tool isn't currently loaded but the class is still
        // autoloadable).
        return $this->resolveSchemaViaReflection($toolClass);
    }

    /**
     * @return array{type: string, properties: object|array<string, mixed>, required: list<string>}|null
     */
    private function resolveSchemaViaReflection(string $toolClass): ?array
    {
        if (!class_exists($toolClass)) {
            return null;
        }

        $ref = new ReflectionClass($toolClass);
        if (!$this->isInstantiableToolClass($ref)) {
            return null;
        }

        /** @var ToolInterface $instance */
        $instance = $ref->newInstance();

        return $instance->getParametersSchema();
    }

    /**
     * Guard before any instantiation: the class must both carry the #[Tool]
     * attribute and implement ToolInterface, and have a no-arg constructor.
     * A persisted tool_class that no longer resolves to a real tool (renamed
     * plugin, type confusion, etc.) must NOT cause a fatal here — history
     * rendering has to stay robust.
     */
    private function isInstantiableToolClass(ReflectionClass $ref): bool
    {
        $ctor = $ref->getConstructor();
        return $ref->isInstantiable()
            && $ref->getAttributes(Tool::class) !== []
            && $ref->implementsInterface(ToolInterface::class)
            && ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0);
    }
}
