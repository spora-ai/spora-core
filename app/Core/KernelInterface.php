<?php

declare(strict_types=1);

namespace Spora\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal contract that the framework's HTTP entry point depends on.
 *
 * Exists so HttpKernel can be unit-tested against a mock without booting
 * the real framework — Kernel is `final` and therefore not directly
 * mockable via Mockery::mock(). Kernel implements this interface; nothing
 * else needs to.
 */
interface KernelInterface
{
    public function handle(Request $request): Response;
}
