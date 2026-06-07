<?php

declare(strict_types=1);

namespace Spora\Services;

use Psr\Log\LoggerInterface;
use ReflectionClass;
use Spora\Tools\Attributes\Tool;

/**
 * Resolves tool identifiers and class names.
 *
 * The resolver owns the bidirectional mapping between the
 * `#[Tool(name: ...)]` attribute value and the fully-qualified class
 * name of the registered tool. This mapping is built once on demand
 * from the `tool_classes` container entry and cached.
 */
final class ToolConfigNameResolver
{
    /** @var array<string, string> tool name (from #[Tool(name:)]) => fully-qualified class name */
    private ?array $toolNameMap = null;

    /** @var list<string> */
    private readonly array $toolClasses;

    private readonly LoggerInterface $logger;

    /**
     * @param list<string> $toolClasses
     */
    public function __construct(
        LoggerInterface $logger,
        array $toolClasses = [],
    ) {
        $this->logger = $logger;
        $this->toolClasses = $toolClasses;
    }

    /**
     * Extract the tool name from the #[Tool] attribute on the class.
     * Falls back to the short class name if the attribute is absent.
     */
    public function getToolName(string $toolClass): string
    {
        $reflection = new ReflectionClass($toolClass);
        $attrs      = $reflection->getAttributes(Tool::class);

        if ($attrs !== []) {
            /** @var Tool $tool */
            $tool = $attrs[0]->newInstance();

            return $tool->name;
        }

        return $reflection->getShortName();
    }

    /**
     * Resolve a tool identifier (from #[Tool(name:)]) to its fully-qualified PHP class name.
     */
    public function resolveToolClass(string $toolName): ?string
    {
        return $this->buildToolNameMap()[$toolName] ?? null;
    }

    /**
     * Return all registered tool class names.
     *
     * @return list<string>
     */
    public function getRegisteredToolClasses(): array
    {
        return $this->toolClasses;
    }

    /**
     * Build the tool name → class map from registered tool classes.
     *
     * @return array<string, string>
     */
    private function buildToolNameMap(): array
    {
        if ($this->toolNameMap !== null) {
            return $this->toolNameMap;
        }

        $this->toolNameMap = [];
        foreach ($this->toolClasses as $class) {
            if (!class_exists($class)) {
                $this->logger->warning('buildToolNameMap: skipping non-existent class', ['class' => $class]);
                continue;
            }
            $reflection = new ReflectionClass($class);
            $attrs = $reflection->getAttributes(Tool::class);
            if ($attrs !== []) {
                /** @var Tool $tool */
                $tool = $attrs[0]->newInstance();
                $this->toolNameMap[$tool->name] = $class;
            }
        }

        return $this->toolNameMap;
    }
}
