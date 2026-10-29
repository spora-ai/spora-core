<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\DueWorkPlugin;

use Spora\Plugins\AbstractPlugin;

/**
 * Fixture plugin contributing a due-work provider.
 *
 * PSR-4 is declared in `plugin.json` rather than spora-core's `composer.json`,
 * so the fixture is self-contained exactly as a shipped plugin is: the loader
 * registers the mapping at boot, before the container resolves anything.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Due Work Plugin';
    }

    /**
     * @return list<class-string<\Spora\Scheduling\DueWorkProviderInterface>>
     */
    public function dueWorkProviders(): array
    {
        $classes = [StubDueWorkProvider::class];

        // The warm-boot test flips this to add a provider without touching
        // the manifest — the case where a sidecar that recorded the provider
        // list would keep reporting the stale one.
        if (getenv('SPORA_FIXTURE_DUE_WORK_SECOND_PROVIDER') === '1') {
            $classes[] = SecondDueWorkProvider::class;
        }

        return $classes;
    }
}
