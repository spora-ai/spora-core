<?php

declare(strict_types=1);

namespace Spora\Core;

use Psr\Container\ContainerInterface;
use Spora\Extensions\AppLoader;
use Spora\Plugins\PluginLoader;

/**
 * The tool classes this install should instantiate, in one place.
 *
 * `ContainerDefinitions` used to assemble the list inline inside its
 * `tool_instances` closure, where the null-safe walk over the app loader sat in a
 * chain of calls that pushed the method over the cognitive-complexity budget. It
 * lives here instead so the definition map only says *what* it wires, and so the
 * answer to "which tools exist" has one owner rather than being implicit in a
 * container entry.
 *
 * The `AppLoader` and `PluginLoader` are both optional at boot: a console command
 * or a test may run without an app or without plugins, and neither is a reason to
 * fail the whole definition set.
 */
final class InstalledToolClasses
{
    /**
     * @return list<string>
     */
    public static function for(ContainerInterface $c): array
    {
        return array_values(array_unique(array_merge(
            $c->get('tool_classes'),
            $c->get(PluginLoader::class)->toolClasses(),
            self::fromApp($c),
        )));
    }

    /**
     * @return list<string>
     */
    private static function fromApp(ContainerInterface $c): array
    {
        if (!$c->has(AppLoader::class)) {
            return [];
        }
        $app = $c->get(AppLoader::class)->getApp();

        return $app === null ? [] : $app->tools();
    }
}
