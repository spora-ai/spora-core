<?php

declare(strict_types=1);

namespace Spora\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolSetting
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly string $description = '',
        public readonly bool $required = false,
        public readonly string $scope = 'global',
        public readonly mixed $default = null,
    ) {}
}
