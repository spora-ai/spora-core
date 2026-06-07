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
                $instances[$toolClass] = (new ReflectionClass($toolClass))->newInstanceWithoutConstructor();
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
