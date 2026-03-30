<?php

declare(strict_types=1);

namespace Spora\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Tool
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters = [],
    ) {}
}
