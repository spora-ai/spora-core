<?php

declare(strict_types=1);

namespace Spora\Apps;

final class PluginsApp implements AppInterface
{
    public function name(): string
    {
        return 'plugins';
    }

    public function displayName(): string
    {
        return 'Plugins';
    }

    public function description(): string
    {
        return 'Installed plugins and their status.';
    }

    public function icon(): string
    {
        return 'puzzle';
    }
}
