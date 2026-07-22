<?php

declare(strict_types=1);

namespace Spora\Tools;

use ReflectionClass;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Traits\HasOperations;

/**
 * Stateless reflection helper that builds the public "tool summary" payload
 * (`tool_class`, `tool_name`, `display_name`, `category`, `icon`,
 * `operations`) from a tool's `#[Tool]` and `#[ToolOperation]` attributes.
 *
 * Shared by {@see \Spora\Http\ToolController} (which adds settings schema on
 * top) and {@see AgentTool} (which enriches a per-agent status
 * payload with display name + icon). Centralising the reflection here keeps
 * the two callers from drifting on attribute semantics — a tool that adds a
 * new attribute only needs to teach this class once.
 */
final class ToolSchemaPresenter
{
    /**
     * @return array{
     *   tool_class: string,
     *   tool_name: string,
     *   display_name: string,
     *   category: string,
     *   icon: string|null,
     *   operations: list<array{name: string, description: string, enabledByDefault: bool, requiresApprovalByDefault: bool, discriminatorKey: string}>
     * }
     */
    public static function summarize(string $toolClass, ?string $icon = null): array
    {
        if (!class_exists($toolClass)) {
            // No class to reflect against — short-classname fallback so the
            // payload still parses on the consumer side.
            return [
                'tool_class'   => $toolClass,
                'tool_name'    => basename(str_replace('\\', '/', $toolClass)),
                'display_name' => basename(str_replace('\\', '/', $toolClass)),
                'category'     => 'general',
                'icon'         => $icon,
                'operations'   => [],
            ];
        }

        $reflection = new ReflectionClass($toolClass);

        $toolAttrs = $reflection->getAttributes(Tool::class);
        /** @var Tool|null $toolAttr */
        $toolAttr   = $toolAttrs !== [] ? $toolAttrs[0]->newInstance() : null;
        $toolName   = $toolAttr->name ?? $reflection->getShortName();
        $displayName = $toolAttr->displayName ?? $toolName;
        $category   = $toolAttr->category ?? 'general';

        $operations = [];
        $usesOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);
        if ($usesOperations) {
            // Bypass the constructor to enumerate #[ToolOperation] declarations
            // for the metadata payload. The instance is held only for attribute
            // reflection via getOperations(), which is a pure metadata reader
            // on the class — it never performs work and never requires
            // injected dependencies. The orchestrator constructs real tool
            // instances via the DI container at execution time.
            // Safe by construction.
            $instance = $reflection->newInstanceWithoutConstructor(); // NOSONAR php:S3011 — see comment above
            foreach ($instance->getOperations() as $op) {
                $operations[] = [
                    'name'                        => $op->name,
                    'description'                 => $op->description,
                    'enabledByDefault'            => $op->enabledByDefault,
                    'requiresApprovalByDefault'   => $op->requiresApprovalByDefault,
                    'discriminatorKey'            => $op->discriminatorKey,
                ];
            }
        }

        return [
            'tool_class'   => $toolClass,
            'tool_name'    => $toolName,
            'display_name' => $displayName,
            'category'     => $category,
            'icon'         => $icon,
            'operations'   => $operations,
        ];
    }
}
