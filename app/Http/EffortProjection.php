<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Models\Agent;
use Spora\Services\EffortService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The payload the effort endpoints answer with.
 *
 * The admin endpoint, the agent endpoint and the two scoped ones report the
 * same thing about the same agent, so they report it through this
 * projection: the envelope and the payload are written once, and a change
 * to either is a change here rather than a change per endpoint.
 */
final class EffortProjection
{
    public function __construct(
        private readonly EffortService $effortService,
    ) {}

    /**
     * The envelope every surface answers with.
     */
    public function respond(Agent $agent): JsonResponse
    {
        return new JsonResponse(['data' => $this->effortService->describe($agent)]);
    }
}
