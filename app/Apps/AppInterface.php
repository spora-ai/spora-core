<?php

declare(strict_types=1);

namespace Spora\Apps;

interface AppInterface
{
    public function name(): string;

    public function displayName(): string;

    public function description(): string;

    public function icon(): string;
}
