<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\AccentPlugin;

use Spora\Apps\AppInterface;
use Spora\Plugins\AbstractPlugin;
use Tests\Fixtures\SpyAccentApp;

/**
 * Companion to the `plugins_with_accent_manifest` fixture set — pairs
 * the plugin manifest's `accent` field (test value: `"amber"`) with
 * an App class whose `accent()` returns a non-enum token, so the
 * AppsController's fallback path (manifest > default) is the only way
 * to land on `"amber"`.
 */
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
