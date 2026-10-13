<?php

declare(strict_types=1);

namespace Spora\Services;

use Psr\Log\LoggerInterface;
use ReflectionClass;
use Spora\Tools\Attributes\SharedSettings;
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
    /** Namespace for shared settings group ids inside the name map. */
    public const GROUP_PREFIX = 'group:';

    private ?array $toolNameMap = null;

    /** @var list<string> */
    private readonly array $toolClasses;

    /** @var list<class-string> */
    private readonly array $settingsGroupClasses;

    private readonly LoggerInterface $logger;

    /**
     * @param list<string> $toolClasses
     */
    /**
 * @param list<class-string> $toolClasses
 * @param list<class-string> $settingsGroupClasses Classes carrying
 *        `#[SharedSettings]`. Indexed by their `group` slug so a group id
 *        resolves like a tool name — without this the only writer of
 *        `tool_configurations` cannot address a group at all, and the whole
 *        shared-settings feature is unreachable from the API.
 */
    public function __construct(
        LoggerInterface $logger,
        array $toolClasses = [],
        array $settingsGroupClasses = [],
    ) {
        $this->logger = $logger;
        $this->toolClasses = $toolClasses;
        $this->settingsGroupClasses = $settingsGroupClasses;
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
     * Read the icon key declared on the tool class via #[Tool(icon: ...)].
     * Returns null when the class has no #[Tool] attribute, the attribute
     * omits `icon`, or the class is not resolvable.
     *
     * Resolution is single-layer: just the per-class override. Plugin-level
     * and default fallbacks are owned by {@see ToolIconResolver}.
     */
    public function getToolIcon(string $toolClass): ?string
    {
        if (!class_exists($toolClass)) {
            return null;
        }

        $reflection = new ReflectionClass($toolClass);
        $attrs      = $reflection->getAttributes(Tool::class);

        if ($attrs === []) {
            return null;
        }

        /** @var Tool $tool */
        $tool = $attrs[0]->newInstance();

        return $tool->icon;
    }

    /**
     * Resolve a tool identifier (from #[Tool(name:)]) to its fully-qualified PHP class name.
     */
    public function resolveToolClass(string $toolName): ?string
    {
        return $this->buildToolNameMap()[$toolName] ?? null;
    }

    /**
     * Resolve a shared settings group's slug to its declaring class.
     *
     * Namespaced under `group:` in the same map rather than indexed bare,
     * because a bare slug that happens to equal a tool name would make a
     * group write silently resolve to a tool write.
     */
    public function resolveGroupClass(string $groupId): ?string
    {
        $class = $this->buildToolNameMap()[self::GROUP_PREFIX . $groupId] ?? null;

        return $class;
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

        // Shared settings groups carry no `#[Tool]` — they are not tools — so
        // the loop above can never see them. Indexed here by their group slug
        // so `PUT /api/v1/tools/{groupId}/settings` resolves, which is what
        // makes the shared credential writable at all.
        foreach ($this->settingsGroupClasses as $class) {
            if (!class_exists($class)) {
                $this->logger->warning('buildToolNameMap: skipping non-existent settings group', ['class' => $class]);
                continue;
            }
            $groupAttrs = (new ReflectionClass($class))->getAttributes(SharedSettings::class);
            if ($groupAttrs === []) {
                continue;
            }
            /** @var SharedSettings $group */
            $group = $groupAttrs[0]->newInstance();
            $this->toolNameMap[self::GROUP_PREFIX . $group->group] = $class;
        }

        return $this->toolNameMap;
    }
}
