<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\SpeechCapabilityController;
use Spora\Http\SpeechProviderConfigController;
use Spora\Http\SpeechTranscribeController;
use Spora\OpenApi\RouteSpecCollector;

/**
 * Routes for the speech-to-text HTTP surface.
 *
 * Lives outside {@see RouteDefinitions} to keep that class under the
 * 20-method Sonar brain-overload threshold (the speech surface arrived
 * as a 21st method).
 *
 * Endpoints (the LLM mirror):
 *
 *   GET    /api/v1/speech/capability              — provider list (auth only)
 *   POST   /api/v1/speech/transcribe              — transcribe a recording (auth + CSRF)
 *   GET    /api/v1/speech/provider-configs        — list configs visible to caller
 *   POST   /api/v1/speech/provider-configs        — create a config (auth + CSRF)
 *   POST   /api/v1/speech/provider-configs/{id}/set-default
 *                                              — mark one global config as default
 *                                                (CRITICAL ordering: registered BEFORE any
 *                                                 /provider-configs/{id} POST so the literal
 *                                                 "set-default" suffix isn't parsed as the
 *                                                 {id} placeholder)
 *   PUT    /api/v1/speech/provider-configs/{id}   — update a config (auth + CSRF)
 *   DELETE /api/v1/speech/provider-configs/{id}   — delete a config (auth + CSRF)
 *   PUT    /api/v1/speech/preference               — set / clear preferred STT config (FK)
 *   GET    /api/v1/speech/preference               — read the current preference (auth only)
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

        // Provider-config surface (LLMConfigController parity).
        $r->addRoute(
            'GET',
            '/api/v1/speech/provider-configs',
            [SpeechProviderConfigController::class, 'index'],
            [AuthMiddleware::class],
        );
        $r->addRoute(
            'POST',
            '/api/v1/speech/provider-configs',
            [SpeechProviderConfigController::class, 'store'],
            [AuthMiddleware::class, CsrfMiddleware::class],
        );

        // "Set as Default" is on /{id}/set-default so the {id} variable
        // can come through normally. (LLMConfigController uses the same
        // pattern.)
        $r->addRoute(
            'POST',
            '/api/v1/speech/provider-configs/{id}/set-default',
            [SpeechProviderConfigController::class, 'setDefault'],
            [AuthMiddleware::class, CsrfMiddleware::class],
        );

        $r->addRoute(
            'PUT',
            '/api/v1/speech/provider-configs/{id}',
            [SpeechProviderConfigController::class, 'update'],
            [AuthMiddleware::class, CsrfMiddleware::class],
        );
        $r->addRoute(
            'DELETE',
            '/api/v1/speech/provider-configs/{id}',
            [SpeechProviderConfigController::class, 'destroy'],
            [AuthMiddleware::class, CsrfMiddleware::class],
        );

        // "Preferred STT config" — a separate URL tree (no /provider-configs
        // prefix) so the {id} collision isn't a concern and there's no
        // ordering constraint. GET is auth-only (read); PUT carries CSRF
        // because it writes to principal_preferences.
        $r->addRoute(
            'GET',
            '/api/v1/speech/preference',
            [SpeechProviderConfigController::class, 'getPreference'],
            [AuthMiddleware::class],
        );
        $r->addRoute(
            'PUT',
            '/api/v1/speech/preference',
            [SpeechProviderConfigController::class, 'setPreferred'],
            [AuthMiddleware::class, CsrfMiddleware::class],
        );
    }
}
