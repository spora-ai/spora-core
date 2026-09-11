<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Speech\SpeechToTextRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Read-only capability endpoint that powers the recording button's
 * conditional render in the frontend composer.
 *
 * Returns the list of every loaded STT provider (one row per provider,
 * regardless of how many plugins ship them), plus a derived
 * `available` / `configured` summary the frontend uses to decide whether
 * to render the recording button at all.
 *
 * Per-agent scope is intentionally `null` for v1 — the controller
 * resolves the per-user effective settings but doesn't accept an agent
 * id, mirroring the rationale in
 * {@see SpeechTranscribeController::transcribeWithProvider()} (per-agent
 * settings are out of scope until the Speech Provider Configuration
 * plan's `stt_provider_configurations` schema is in place). Anonymous
 * visitors (no session) still get a 200 with `configured: false` and
 * providers built via `describe(0, null)` so the SPA can render the
 * "please log in" empty state without a separate code path.
 *
 * Mirrors the shape of {@see MediaAllowedTypesController}:
 * read-only, auth-only (no CSRF).
 */
final class SpeechCapabilityController
{
    public function __construct(
        private readonly SpeechToTextRegistry $registry,
        private readonly AuthService $auth,
    ) {}

    #[OA\Get(
        path: '/api/v1/speech/capability',
        summary: 'Speech-to-text provider capability',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Capability summary',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean'),
                        new OA\Property(property: 'configured', type: 'boolean'),
                        new OA\Property(
                            property: 'providers',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'display_name', type: 'string'),
                                    new OA\Property(property: 'configured', type: 'boolean'),
                                    new OA\Property(property: 'has_global_default', type: 'boolean'),
                                    new OA\Property(property: 'config_id', type: 'integer', nullable: true),
                                ],
                                type: 'object',
                            ),
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function index(): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        $providers = $this->registry->describe($userId, null);

        return new JsonResponse([
            'data' => [
                'available'  => $providers !== [],
                'configured' => $this->registry->configuredProvider($userId, null) !== null,
                'providers'  => $providers,
            ],
        ]);
    }
}
