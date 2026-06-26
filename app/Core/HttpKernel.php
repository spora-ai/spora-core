<?php

declare(strict_types=1);

namespace Spora\Core;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HTTP entry point for the Spora framework.
 *
 * Encapsulates framework boot, request dispatch, and the JSON-500 fallback
 * when boot or dispatch fails. The operator install's public/index.php
 * is a thin shell that delegates to this class — same shape as Symfony's
 * App\Kernel pattern.
 *
 * Typical entry point:
 *
 *     $kernel   = new HttpKernel();
 *     $response = $kernel->handle(Request::createFromGlobals());
 *     $response->send();
 *
 * Tests pass a pre-built KernelInterface implementation to avoid the
 * real framework boot. In production, the constructor creates a fresh
 * Kernel on first handle().
 */
final class HttpKernel
{
    public function __construct(
        private readonly ?KernelInterface $kernel = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $kernel = $this->kernel ?? new Kernel();
            return $kernel->handle($request);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[Spora] Boot failure — %s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            return new JsonResponse(
                ['error' => ['code' => 'INTERNAL_SERVER_ERROR', 'message' => 'Application failed to start.']],
                500,
            );
        }
    }
}