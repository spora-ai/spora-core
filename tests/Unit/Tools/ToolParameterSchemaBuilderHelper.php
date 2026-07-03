<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use ReflectionAttribute;
use ReflectionClass;

/**
 * Helper that walks the inheritance chain to collect attributes — matches
 * ToolParameterSchemaBuilder's own behaviour so the invariants in
 * AbstractToolTest see the same merged attribute list the builder produces.
 * Kept here to avoid leaking a test-only utility into app/.
 */
final class ToolParameterSchemaBuilderHelper
{
    /**
     * @return list<ReflectionAttribute<object>>
     */
    public static function collectAttributes(ReflectionClass $ref, string $attributeClass): array
    {
        $attrs = [];
        $current = $ref;
        while ($current !== false) {
            foreach ($current->getAttributes($attributeClass) as $attr) {
                $attrs[] = $attr;
            }
            $current = $current->getParentClass();
        }
        return $attrs;
    }
}
