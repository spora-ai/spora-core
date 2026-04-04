<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// Serve the SPA for all non-API routes in production (dist must be built first).
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (!str_starts_with($requestPath, '/api/') && file_exists(__DIR__ . '/dist/index.html')) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/dist/index.html');
    exit;
}

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
