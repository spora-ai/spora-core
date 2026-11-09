<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Services\AgentServiceInterface;
use Spora\Speech\SpeechToTextRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read-only capability endpoint that powers the recording button's
 * conditional render in the frontend composer.
 *
 * Returns the list of every loaded STT provider (one row per provider,
 * regardless of how many plugins ship them), plus a derived
 * `available` / `configured` summary the frontend uses to decide whether
 * to render the recording button at all. Every row also carries the
 * effective-class + tier label + config-id resolved by
 * {@see SpeechToTextRegistry::describeWithConfig()} so the SPA can
 * render "Currently using: X" against a single source of truth.
 *
 * With `?agent_id=N` the cascade resolution reflects the agent's own
 * principal (user vs. group) instead of the caller's user-principal —
 * the agent-settings page uses this so the badge next to the dropdown
 * says "group default" for a group-owned agent, not "user default".
 * Missing/malformed `?agent_id` falls back to the unscoped path so the
 * composer (no agent context) keeps working as-is. An `?agent_id=N` the
 * caller cannot see is a 404: the cascade resolves that agent's own
 * principal's configuration, so the response would otherwise carry
 * another principal's configuration metadata.
 *
 * Mirrors the shape of {@see MediaAllowedTypesController}:
 * read-only, auth-only (no CSRF).
 */
final class SpeechCapabilityController
{
    use JsonControllerHelpers;

    private const MSG_AGENT_NOT_FOUND = 'Agent not found.';

    public function __construct(
        private readonly SpeechToTextRegistry $registry,
        private readonly AuthService $auth,
        private readonly AgentServiceInterface $agentService,
    ) {}

    #[OA\Get(
        path: '/api/v1/speech/capability',
        summary: 'Speech-to-text provider capability',
        parameters: [
            new OA\Parameter(
                name: 'agent_id',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, nullable: true),
                description: 'Scope the cascade to this agent\'s principal (user or group). Omit for caller-scoped (composer).',
            ),
        ],
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
    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        $agentId = $this->parseAgentId($request);

        // The agent's cascade resolves its own principal's configuration,
        // so the caller has to be able to see the agent — otherwise the
        // response carries another principal's configuration metadata.
        if ($agentId !== null && $this->agentService->getAgent($agentId, (int) $userId) === null) {
            return $this->notFound('AGENT_NOT_FOUND', self::MSG_AGENT_NOT_FOUND);
        }

        // "configured" summary: a provider was resolved AND it self-
        // reports configured. A resolved class is not enough — its
        // configuration may carry no credentials — so the summary
        // reflects whether transcribing would actually work, not
        // whether a class exists.
        $resolvedProvider = $this->registry->configuredProvider($userId, $agentId);
        $isReady = $resolvedProvider !== null && $resolvedProvider->isConfigured();

        $rows = $this->registry->describeWithConfig($userId, $agentId);

        return new JsonResponse([
            'data' => [
                'available' => $rows !== [],
                'configured' => $isReady,
                'providers' => $rows,
            ],
        ]);
    }

    /**
     * Parse a positive integer `?agent_id=N` query parameter. Returns
     * `null` when missing, negative, zero, or non-numeric; non-positive
     * values yield `null` so the controller falls back to the unscoped
     * composer path instead of erroring. Mirrors
     * {@see LLMConfigController::parseAgentId()} so both controllers
     * stay consistent — the agent-settings page can pass `agent_id`
     * blindly and either of these will treat it the same way.
     */
    private function parseAgentId(Request $request): ?int
    {
        $raw = $request->query->get('agent_id');
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
