<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Apps\VueAppInterface;

/**
 * Vue-bundle-aware stub App. Lets the AppsController tests exercise the
 * `VueAppInterface` branch in `resolveFrontendEntry()` — the contract
 * added in PR #125 so plugins can ship a pre-built IIFE without writing
 * a custom PHP entry point.
 */
final class StubVueApp implements VueAppInterface
{
    public function __construct(private readonly string $entry = 'main.js') {}

    public function name(): string
    {
        return 'stub-vue-app';
    }

    public function displayName(): string
    {
        return 'Stub Vue App';
    }

    public function description(): string
    {
        return 'A Vue app fixture for AppsController tests';
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    public function entry(): string
    {
        return $this->entry;
    }
}

/**
 * Sibling fixture whose `entry()` returns an empty string. The AppsController
 * must omit `frontendEntry` from the payload in this case — `entry()`
 * returning "" is the documented "I have no frontend bundle" signal.
 */
final class StubVueAppEmpty implements VueAppInterface
{
    public function name(): string
    {
        return 'stub-vue-empty';
    }

    public function displayName(): string
    {
        return 'Stub Vue Empty';
    }

    public function description(): string
    {
        return 'A Vue app fixture with empty entry';
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    public function entry(): string
    {
        return '';
    }
}
