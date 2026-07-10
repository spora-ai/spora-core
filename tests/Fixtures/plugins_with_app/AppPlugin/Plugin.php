<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\AppPlugin;

use Spora\Apps\AppInterface;
use Spora\Plugins\AbstractPlugin;
use Tests\Fixtures\StubVueApp;

/**
 * Test fixture plugin whose only contribution is the StubVueApp. Lets
 * AppsControllerTest exercise the slug-lookup path in the apps API
 * (the slug is needed by the host SPA to construct the bundle URL
 * `/plugins/<slug>/<entry>`; it isn't derivable from the app's name
 * in general).
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'App Plugin';
    }

    /**
     * @return list<class-string<AppInterface>>
     */
    public function apps(): array
    {
        return [
            StubVueApp::class,
        ];
    }
}
