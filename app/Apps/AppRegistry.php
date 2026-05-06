<?php

declare(strict_types=1);

namespace Spora\Apps;

final class AppRegistry
{
    /** @var array<string, class-string<AppInterface>> */
    private array $apps = [];

    public function register(string $appClass): void
    {
        $app = new $appClass();
        $this->apps[$app->name()] = $appClass;
    }

    /** @return array<string, AppInterface> */
    public function all(): array
    {
        $result = [];
        foreach ($this->apps as $name => $appClass) {
            $result[$name] = new $appClass();
        }
        return $result;
    }

    public function get(string $name): ?AppInterface
    {
        if (! isset($this->apps[$name])) {
            return null;
        }
        return new $this->apps[$name]();
    }
}
