<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';

use Spora\Core\Kernel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

try {
    $kernel = new Kernel();
} catch (Throwable $e) {
    error_log(sprintf('[Spora] Boot failure — %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    (new JsonResponse(
        ['error' => ['code' => 'INTERNAL_SERVER_ERROR', 'message' => 'Application failed to start.']],
        500,
    ))->send();

    exit(1);
}

$request  = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
