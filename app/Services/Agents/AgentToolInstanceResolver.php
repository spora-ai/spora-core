<?php

declare(strict_types=1);

namespace Spora\Services\Agents;

use ReflectionClass;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Throwable;

/**
 * Pure tool-class reflection helpers used by the AgentService facade
 * and its override/operation resolvers. No DB access, no I/O.
 */
final class AgentToolInstanceResolver
{
    /**
     * Bypass the tool constructor to read tool metadata (e.g. #[Tool] attribute,
     * #[ToolSetting] declarations, #[ToolOperation] handles) without triggering
     * side effects from tool constructors. Results are memoized per process.
     */
    public function resolveToolInstance(string $toolClass): ?object
    {
        static $instances = [];
        if (!class_exists($toolClass)) {
            return null;
        }
        if (!isset($instances[$toolClass])) {
            try {
                // Bypass the tool constructor to read tool metadata (e.g. #[Tool]
                // attribute, #[ToolSetting] declarations) without triggering side
                // effects from tool constructors. The instance is held only for
                // attribute reflection and is never passed to a consumer; the
                // orchestrator and resolvers construct their own tool instances
                // via the DI container. Safe by construction.
                // phpcs:ignore Generic.PHP.NoSilencedErrors,SlevomatCodingStandard.ControlStructures.AssignmentInCondition
                $instances[$toolClass] = (new ReflectionClass($toolClass))->newInstanceWithoutConstructor(); // NOSONAR php:S3011 — see comment above
            } catch (Throwable) {
                return null;
            }
        }
        return $instances[$toolClass];
    }

    public function resolveToolName(string $toolClass): string
    {
        if (!class_exists($toolClass)) {
            return basename(str_replace('\\', '/', $toolClass));
        }

        $reflection = new ReflectionClass($toolClass);
        $attrs = $reflection->getAttributes(Tool::class);

        if ($attrs !== []) {
            return $attrs[0]->newInstance()->name;
        }

        return $reflection->getShortName();
    }

    /**
     * @return list<string>
     */
    public function getToolPasswordKeys(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $keys = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $instance */
            $instance = $attr->newInstance();
            if ($instance->type === 'password') {
                $keys[] = $instance->key;
            }
        }

        return $keys;
    }
}
