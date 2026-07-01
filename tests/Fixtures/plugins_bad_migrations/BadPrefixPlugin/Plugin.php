<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\BadPrefixPlugin;

use Spora\Plugins\AbstractPlugin;

final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Bad Prefix Plugin';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/migrations';
    }
}
