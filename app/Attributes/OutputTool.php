<?php

declare(strict_types=1);

namespace Spora\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class OutputTool
{
    public function __construct(
        public readonly bool $requiresApproval = true,
    ) {}
}
