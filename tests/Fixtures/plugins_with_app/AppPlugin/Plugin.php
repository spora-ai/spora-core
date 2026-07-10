<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\AppPlugin;

use Spora\Apps\AppInterface;
use Spora\Plugins\AbstractPlugin;
use Tests\Fixtures\StubVueApp;

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
