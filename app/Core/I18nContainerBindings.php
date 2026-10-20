<?php

declare(strict_types=1);

namespace Spora\Core;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\I18n\CatalogLoader;
use Spora\I18n\LocaleResolver;
use Spora\I18n\PluginCatalogDirectories;
use Spora\I18n\Translator;
use Spora\Plugins\PluginLoader;

/**
 * DI definitions for the translation layer.
 *
 * Lives outside {@see ContainerDefinitions} so that class stays within the
 * S1448 method-count ceiling — the same reason the orchestrator slice
 * lives in {@see OrchestratorContainerBindings}.
 */
final class I18nContainerBindings
{
    public static function all(): array
    {
        return [
            CatalogLoader::class => static fn(ContainerInterface $c): CatalogLoader => new CatalogLoader(
                $c->has(PluginLoader::class)
                    ? PluginCatalogDirectories::from($c->get(PluginLoader::class)->getPlugins())
                    : [],
                null,
                $c->has(LoggerInterface::class) ? $c->get(LoggerInterface::class) : null,
            ),

            // The translator is request state — {@see \Spora\Core\Kernel}
            // sets its locale once per request, before anything serialises
            // a message — so PHP-DI has to hand out one shared instance,
            // which its default definition scoping does.
            Translator::class => static fn(ContainerInterface $c): Translator
                => new Translator($c->get(CatalogLoader::class)),

            LocaleResolver::class => static fn(ContainerInterface $c): LocaleResolver => new LocaleResolver(
                $c->get(CatalogLoader::class),
                (string) $c->get(Config::class)->getString('SPORA_DEFAULT_LANG', Translator::FALLBACK_LOCALE),
            ),
        ];
    }
}
