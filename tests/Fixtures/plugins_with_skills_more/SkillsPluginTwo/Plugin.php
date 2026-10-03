<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SkillsPluginTwo;

use Spora\Plugins\AbstractPlugin;

/**
 * Second fixture plugin contributing the *same* provider class as
 * `plugins_with_skills`. Two plugins naming one class must produce one
 * registry entry, not two copies of the same instance.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Skills Plugin Two';
    }

    /**
     * @return list<class-string<\Spora\Skills\SkillProviderInterface>>
     */
    public function skillProviders(): array
    {
        return [\Tests\Fixtures\Skills\StubSkillProvider::class];
    }
}
