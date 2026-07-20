<?php

declare(strict_types=1);

namespace Spora\Tools;

use ReflectionClass;
use Spora\Tools\Attributes\ToolSetting;

/**
 * Stateless reflection over `#[ToolSetting]` attribute declarations.
 *
 * PHP's `ReflectionClass::getAttributes()` does not walk the parent
 * chain, so a setting declared on an abstract base is invisible from
 * a concrete subclass. Every reader in the framework
 * (`LLMConfigSchemaInspector`, `ToolConfigSchemaInspector`,
 * `ToolController::toolSchemaResource`) needs the same walk; this
 * helper centralises it.
 */
final class ToolSettingSchema
{
    /**
     * Collect every `#[ToolSetting]` declared on the class and any
     * ancestor, deduplicated by key.
     *
     * Parents are walked first so that a subclass redeclaration of
     * the same key wins — matching PHP's natural attribute lookup
     * semantics and the "last write wins" contract that consumers
     * (settings forms, defaults tables, the LLM projection) depend on.
     *
     * @return list<ToolSetting>
     */
    public static function collect(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $ref = new ReflectionClass($class);
        $byKey = [];

        $cursor = $ref->getParentClass();
        while ($cursor !== false) {
            foreach ($cursor->getAttributes(ToolSetting::class) as $attr) {
                $instance = $attr->newInstance();
                $byKey[$instance->key] = $instance;
            }
            $cursor = $cursor->getParentClass();
        }

        foreach ($ref->getAttributes(ToolSetting::class) as $attr) {
            $instance = $attr->newInstance();
            $byKey[$instance->key] = $instance;
        }

        return array_values($byKey);
    }
}
