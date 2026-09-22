<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\AccentPlugin;

use Spora\Apps\AppInterface;
use Spora\Plugins\AbstractPlugin;
use Tests\Fixtures\SpyAccentApp;

/** Manifest `accent: amber` paired with a non-enum PHP `accent()`, so manifest-overrides-bad-PHP is the only path that lands on `amber`. */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Accent Manifest Plugin';
    }

    /**
     * @return list<class-string<AppInterface>>
     */
    public function apps(): array
    {
        return [
            SpyAccentApp::class,
        ];
    }
}
