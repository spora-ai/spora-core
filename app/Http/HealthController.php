<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

final class HealthController
{
    public function check(): JsonResponse
    {
        try {
            Capsule::connection()->getPdo()->query('SELECT 1');
            return new JsonResponse(['status' => 'ok', 'database' => 'connected']);
        } catch (Throwable $e) {
            return new JsonResponse(
                ['status' => 'error', 'database' => 'unavailable', 'message' => $e->getMessage()],
                503,
            );
        }
    }
}
