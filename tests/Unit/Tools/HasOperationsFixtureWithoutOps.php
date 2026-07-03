<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

/** Fixture that uses HasOperations without any #[ToolOperation] attributes. */
final class HasOperationsFixtureWithoutOps
{
    use \Spora\Tools\Traits\HasOperations;
}
