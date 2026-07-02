<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use Spora\Extensions\AbstractExtension;
use Spora\Tools\ToolInterface;

/**
 * Subclass that overrides only tools() — proves partial overrides work.
 */
final class ToolsOnlyExtension extends AbstractExtension
{
    /** @var list<class-string<ToolInterface>> */
    private array $tools;

    /**
     * @param list<class-string<ToolInterface>> $tools
     */
    public function __construct(array $tools)
    {
        $this->tools = $tools;
    }

    public function getName(): string
    {
        return 'ToolsOnly';
    }

    /** @return list<class-string<ToolInterface>> */
    public function tools(): array
    {
        return $this->tools;
    }
}
