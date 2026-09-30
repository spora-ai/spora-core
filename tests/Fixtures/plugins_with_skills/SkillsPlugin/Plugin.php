<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SkillsPlugin;

use Spora\Plugins\AbstractPlugin;
use Tests\Fixtures\Skills\StubSkillProvider;

/**
 * Fixture plugin contributing one {@see \Spora\Skills\SkillProviderInterface}.
 * Used to prove the `skillProviders()` hook reaches the container and that a
 * plugin's provider lands *after* core's, so it cannot shadow a shipped skill.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Skills Plugin';
    }

    /**
     * @return list<class-string<\Spora\Skills\SkillProviderInterface>>
     */
    public function skillProviders(): array
    {
        return [StubSkillProvider::class];
    }
}
