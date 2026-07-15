<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reports the dynamic upload allowlist to the frontend composer.
 *
 * - GET /api/v1/media/allowed-types
 *
 * Combines the static text allowlist, every registered
 * {@see \Spora\Services\MediaArchive\MediaConverterInterface}'s
 * supported MIME types, and (when `?agent_id=` resolves an agent
 * whose LLM reports `supportsImageInput=true`) the image MIME types.
 *
 * The frontend fetches this once per composer mount and uses the
 * result to populate the `<input type="file" accept="…">` attribute
 * and the legend ("Allowed: PDF, TXT, MD, …").
 */
final class MediaAllowedTypesController
{
    public function __construct(
        private readonly MediaAllowedTypesService $allowedTypes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $agentIdRaw = $request->query->get('agent_id');
        $agentId    = is_string($agentIdRaw) && ctype_digit($agentIdRaw) ? (int) $agentIdRaw : null;

        $mimeTypes = $this->allowedTypes->allowedMimeTypes($agentId);
        $extensions = $this->allowedTypes->allowedExtensions($agentId);

        sort($mimeTypes);
        sort($extensions);

        return new JsonResponse([
            'data' => [
                'mime_types' => $mimeTypes,
                'extensions' => $extensions,
            ],
        ]);
    }
}
