<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use InvalidArgumentException;

final class UnknownContentBlockTypeException extends InvalidArgumentException
{
    public function __construct(string $type)
    {
        parent::__construct("Unknown content block type: {$type}");
    }
}
