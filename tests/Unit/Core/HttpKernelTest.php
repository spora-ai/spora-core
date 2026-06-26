<?php

declare(strict_types=1);

use Spora\Core\HttpKernel;
use Spora\Core\KernelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HttpKernel owns the boot + dispatch + JSON-500-on-failure contract.
 * The operator install's public/index.php delegates here so it stays
 * a thin Symfony-style shell.
 *
 * Tests inject a mocked KernelInterface so we don't boot the real
 * framework. The interface exists precisely so Kernel (which is final)
 * can be replaced in tests without lifting the `final` modifier.
 */

afterEach(function () {
    Mockery::close();
});

test('handle() returns the response from the injected Kernel', function (): void {
    $request  = Request::create('/');
    $response = new Response('hello', 200);

    $kernel = Mockery::mock(KernelInterface::class);
    $kernel->shouldReceive('handle')->once()->with($request)->andReturn($response);

    $httpKernel = new HttpKernel($kernel);
    $result = $httpKernel->handle($request);

    expect($result)->toBe($response);
    expect($result->getStatusCode())->toBe(200);
});

test('handle() returns a JSON 500 when the inner Kernel throws', function (): void {
    $request = Request::create('/');

    // Redirect error_log to /dev/null while exercising the catch arm.
    $previousLog = ini_set('error_log', '/dev/null');

    try {
        $kernel = Mockery::mock(KernelInterface::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->with($request)
            ->andThrow(new \RuntimeException('boom'));

        $httpKernel = new HttpKernel($kernel);
        $result = $httpKernel->handle($request);
    } finally {
        ini_set('error_log', $previousLog !== false ? $previousLog : 'syslog');
    }

    expect($result)->toBeInstanceOf(JsonResponse::class);
    expect($result->getStatusCode())->toBe(500);
    expect(json_decode($result->getContent(), true))
        ->toBe([
            'error' => [
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'Application failed to start.',
            ],
        ]);
});