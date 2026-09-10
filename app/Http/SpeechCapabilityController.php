<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
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
 * Mirrors the shape of {@see MediaAllowedTypesController}:
 * read-only, auth-only (no CSRF).
 */
final class SpeechCapabilityController
{
    public function __construct(private readonly SpeechToTextRegistry $registry) {}

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
        $providers = $this->registry->describe();

        return new JsonResponse([
            'data' => [
                'available'  => $providers !== [],
                'configured' => $this->registry->configuredProvider() !== null,
                'providers'  => $providers,
            ],
        ]);
    }
}
