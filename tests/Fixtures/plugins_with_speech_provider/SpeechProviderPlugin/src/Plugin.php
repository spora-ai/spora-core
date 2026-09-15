<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SpeechProviderPlugin;

use Spora\Plugins\AbstractPlugin;

/**
 * Test fixture plugin that contributes a SpeechToTextProvider
 * (see FixtureProvider in this namespace). The plugin entry point
 * itself returns the FQCN from {@see speechToTextProviders()}.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Speech Provider Fixture';
    }

    /** @return list<class-string<\Spora\Speech\SpeechToTextProviderInterface>> */
    public function speechToTextProviders(): array
    {
        return [FixtureProvider::class];
    }
}
