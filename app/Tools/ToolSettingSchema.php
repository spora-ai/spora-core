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
     * The nearest declaration wins: a subclass overrides its parent, a
     * parent overrides its own parent. The chain is therefore applied
     * oldest-first so the last write is the most specific one — walking
     * it the other way lets a grandparent's stale declaration replace
     * the parent's, which is how a `password` key loses its masking and
     * an `exposeToLlm: false` key loses its disclosure restriction.
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

        foreach (self::ancestorsOldestFirst($ref) as $ancestor) {
            foreach ($ancestor->getAttributes(ToolSetting::class) as $attr) {
                $instance = $attr->newInstance();
                $byKey[$instance->key] = $instance;
            }
        }

        foreach ($ref->getAttributes(ToolSetting::class) as $attr) {
            $instance = $attr->newInstance();
            $byKey[$instance->key] = $instance;
        }

        return array_values($byKey);
    }

    /**
     * The ancestor chain, oldest ancestor first.
     *
     * `getParentClass()` walks towards the root, so the collected list is
     * reversed before it is applied — see {@see self::collect()}.
     *
     * @return list<ReflectionClass>
     */
    private static function ancestorsOldestFirst(ReflectionClass $ref): array
    {
        $ancestors = [];

        $cursor = $ref->getParentClass();
        while ($cursor !== false) {
            $ancestors[] = $cursor;
            $cursor = $cursor->getParentClass();
        }

        return array_reverse($ancestors);
    }
}
