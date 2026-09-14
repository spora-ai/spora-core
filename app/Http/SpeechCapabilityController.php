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
 * to render the recording button at all. Every row also carries the
 * effective-class + tier label resolved by
 * {@see SpeechToTextRegistry::resolveEffectiveClassWithSource()} so
 * the SPA can render "Currently using: X" against a single source of
 * truth.
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
                                    new OA\Property(property: 'effective_class', type: 'string', nullable: true),
                                    new OA\Property(property: 'effective_source', type: 'string', nullable: true),
                                    new OA\Property(property: 'effective_config_id', type: 'integer', nullable: true),
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
        $providers = $this->registry->all();

        [$effectiveClass, $effectiveSource] = $this->registry->describe($userId, null);

        // "configured" summary: a provider was resolved AND it self-
        // reports configured. The fallback tier picks the first
        // registered class (which may not have an api_key yet) — the
        // summary reflects whether transcribing would actually work,
        // not whether a class exists.
        $resolvedProvider = $this->registry->configuredProvider($userId, null);
        $isReady = $resolvedProvider !== null && $resolvedProvider->isConfigured();

        $rows = [];
        foreach ($providers as $provider) {
            $rows[] = [
                'name' => $provider->getName(),
                'display_name' => $provider->getDisplayName(),
                'configured' => $provider->isConfigured(),
                'effective_class' => $effectiveClass,
                'effective_source' => $effectiveSource,
                'effective_config_id' => null,
            ];
        }

        return new JsonResponse([
            'data' => [
                'available' => $providers !== [],
                'configured' => $isReady,
                'providers' => $rows,
            ],
        ]);
    }
}
