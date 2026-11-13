<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use OpenApi\Attributes as OA;
use Spora\Http\EffortProjection;
use Spora\Http\JsonControllerHelpers;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\ApiKeys\ApiKeyReach;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The reasoning-effort levels one agent's LLM configuration offers.
 *
 * One implementation serves both the group surface and the global surface:
 * the scope comes from the key, and the reach decides which agents exist as
 * far as the caller is concerned. The OpenAPI tag is therefore derived from
 * the path rather than declared, so each surface gets its own page.
 */
final class ScopedApiEffortController
{
    use JsonControllerHelpers;

    private const MSG_NO_SUCH_AGENT = 'No such agent, or not one this key may reach.';

    public function __construct(
        private readonly ApiKeyReach $reach,
        private readonly EffortProjection $projection,
    ) {}

    #[OA\Get(summary: "The reasoning-effort levels an agent's LLM configuration offers")]
    #[OA\Response(response: 200, description: 'JSON envelope: `{data: {supported: bool, levels: [{value, label}], default: string|null}}`.')]
    #[OA\Response(response: 404, description: self::MSG_NO_SUCH_AGENT)]
    public function show(Request $request, int $agentId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $agent = $this->reach->targetAgent($context, $agentId);

        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', self::MSG_NO_SUCH_AGENT);
        }

        return $this->projection->respond($agent);
    }
}
