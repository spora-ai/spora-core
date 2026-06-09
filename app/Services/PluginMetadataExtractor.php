<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Tools\Attributes\Tool;

/**
 * Extracts the human-readable name + description for each plugin-contributed tool.
 *
 * Plugins return FQCNs from PluginInterface::tools(); instantiating them is unsafe
 * (some tool constructors read config or open HTTP clients), so we only read the
 * #[Tool] attribute via reflection.
 */
final class PluginMetadataExtractor
{
    /**
     * @param list<class-string> $toolClasses
     *
     * @return list<array{name: string, description: string}>
     */
    public function extract(array $toolClasses): array
    {
        $out = [];

        foreach ($toolClasses as $fqcn) {
            if (!class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
            $attributes = $reflection->getAttributes(Tool::class);

            if ($attributes === []) {
                continue;
            }

            /** @var Tool $tool */
            $tool = $attributes[0]->newInstance();
            $out[] = [
                'name'        => $tool->name,
                'description' => $tool->description,
            ];
        }

        return $out;
    }
}
