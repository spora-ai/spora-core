<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\SpeechCapabilityController;
use Spora\Http\SpeechTranscribeController;
use Spora\OpenApi\RouteSpecCollector;

/**
 * Routes for the speech-to-text HTTP surface.
 *
 * Lives outside {@see RouteDefinitions} to keep that class under the
 * 20-method Sonar brain-overload threshold (the speech surface arrived
 * as a 21st method).
 */
final class SpeechRouteDefinitions
{
    public static function register(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        // GET capability endpoint is auth-only (matches /media/allowed-types
        // — read-only state, no CSRF required).
        $r->addRoute(
            'GET',
            '/api/v1/speech/capability',
            [SpeechCapabilityController::class, 'index'],
            [AuthMiddleware::class],
        );

        // POST transcribe is state-changing — full auth + CSRF.
        $r->addRoute(
            'POST',
            '/api/v1/speech/transcribe',
            [SpeechTranscribeController::class, 'transcribe'],
            [AuthMiddleware::class, CsrfMiddleware::class],
        );
    }
}
