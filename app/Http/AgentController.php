<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Spora\Services\AgentFavoriteServiceInterface;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentResource;
use Spora\Services\AgentResourceContext;
use Spora\Services\AgentServiceInterface;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolIconResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Agent CRUD endpoints.
 *
 * Tool enablement / status / overrides are handled by AgentToolController
 * and AgentOverrideController respectively. Agent ownership transfer
 * (POST /api/v1/agents/{id}/transfer) is handled by AgentTransferController.
 */
final class AgentController
{
    use JsonControllerHelpers;

    private const MSG_AGENT_NOT_FOUND = 'Agent not found.';
    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';

    /**
     * Columns the multi-select picker (ToolSettingField) is allowed to
     * request via `?select=…`. Anything outside this list is silently
     * dropped so we don't widen the API surface when the schema grows.
     */
    private const SELECTABLE_COLUMNS = ['id', 'name'];

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
        private readonly AgentFavoriteServiceInterface $favoriteService,
        private readonly ?DriverFactory $driverFactory = null,
        private readonly ?ToolIconResolver $toolIconResolver = null,
        private readonly ?AgentPictureService $pictureService = null,
        private readonly ?PrincipalService $principalService = null,
        private readonly ?PrincipalResolver $principalResolver = null,
    ) {}

    /**
     * GET /api/v1/agents
     *
     * Optional `?select=id,name` query param projects to a subset of columns
     * (used by the multi-select ToolSetting field to fetch the agent list
     * without serializing the full payload). Columns are allowlisted so
     * clients can't request internal fields like `system_prompt`. Backward-
     * compatible: no `?select` returns the full payload via AgentService.
     */
    public function index(?Request $request = null): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        // `?principal_id=` is repeatable and intersects with the user's
        // visible principals — the caller can never scope to a principal
        // they don't own. Empty list = "no filter, show every visible
        // agent", matching the legacy behaviour.
        $principalFilter = $this->resolvePrincipalFilter($request, $userId);

        $select = $request?->query->get('select');
        if (is_string($select) && $select !== '') {
            $requested = array_values(array_filter(
                array_map('trim', explode(',', $select)),
                static fn(string $c): bool => $c !== '',
            ));
            // Only safe-for-picker columns are exposed via ?select. Anything
            // else is silently dropped so future schema additions don't leak.
            $columns = array_values(array_intersect($requested, self::SELECTABLE_COLUMNS));
            if ($columns !== []) {
                $principalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
                // Caller may have no principals — likely a freshly-registered
                // user whose principal hasn't been materialised yet. Materialise
                // the user-principal on the fly so the picker doesn't render
                // empty even though ownership will work going forward.
                if ($principalIds === [] && $this->principalService !== null) {
                    $principalIds = [(int) $this->principalService->ensureUserPrincipal($userId)->id];
                }
                $query = Agent::whereIn('principal_id', $principalIds);
                if ($principalFilter !== null) {
                    $query->whereIn('principal_id', $principalFilter);
                }
                $agents = $query->orderBy('name')->get($columns)->all();
                return new JsonResponse(['data' => ['agents' => $agents]]);
            }
        }

        $agents = $this->agentService->getAgentsForUser($userId, $principalFilter);

        return new JsonResponse(['data' => ['agents' => $agents]]);
    }

    /**
     * Parse `?principal_id=` (single or repeated) and intersect with the
     * user's visible principals. Returns null when the caller didn't ask
     * for a filter (the agent service returns every visible agent in that
     * case). Returns an empty list when the user asked for a filter
     * but every requested principal is outside their visibility scope —
     * the agent service then returns an empty payload without exposing
     * the existence of out-of-scope principals.
     *
     * Accepts all three syntaxes Symfony exposes:
     *   `?principal_id=10`              — single value, scalar.
     *   `?principal_id=10&principal_id=20` — repeated, becomes array.
     *   `?principal_id[]=10&principal_id[]=20` — explicit array.
     * `query->all('principal_id')` throws on a single string, so we read
     * the whole bag and pull the slot — that path yields scalar OR array
     * without complaint.
     *
     * @return list<int>|null
     */
    private function resolvePrincipalFilter(?Request $request, int $userId): ?array
    {
        return AgentFilterParser::parsePrincipalFilter($request, $userId, $this->principalResolver, $this->principalService);
    }

    /**
     * POST /api/v1/agents
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $principalId = $this->resolvePrincipalIdForCreate($userId, $body);

        $data = [
            'name'          => $name,
            'description'   => trim((string) ($body['description'] ?? '')) ?: null,
            'system_prompt' => trim((string) ($body['system_prompt'] ?? '')) ?: null,
            // `notes` accepted on POST so an operator can seed runbook text
            // on first-create, not just PATCH-edit. Mirrors the PATCH path.
            'notes'         => isset($body['notes']) && is_string($body['notes']) && $body['notes'] !== ''
                ? $body['notes']
                : null,
            'llm_driver_config_id' => isset($body['llm_driver_config_id']) ? (int) $body['llm_driver_config_id'] : null,
            'max_steps'     => (int) ($body['max_steps'] ?? 10),
            'allow_followup' => array_key_exists('allow_followup', $body) ? (bool) $body['allow_followup'] : true,
        ];

        $agent = $this->agentService->createAgent($userId, $data, $principalId);

        return new JsonResponse(
            ['data' => ['agent' => AgentResource::toArray($agent, $this->agentResourceContext($agent))]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Resolve the owning principal for {@see store()}. Accepts an
     * explicit `principal_id` in the body when the caller is admin or
     * owns the target principal; otherwise defaults to the caller's
     * user-principal. Returns null when the field is omitted and no
     * PrincipalService is wired (legacy test path).
     *
     * @param  array<string, mixed> $body
     */
    private function resolvePrincipalIdForCreate(int $userId, array $body): ?int
    {
        $requested = $body['principal_id'] ?? null;
        if ($requested === null || (int) $requested <= 0) {
            return null;
        }
        $requested = (int) $requested;

        if ($this->principalService === null) {
            return null;
        }

        return $this->authorisePrincipalIdOrFallback($userId, $requested);
    }

    private function authorisePrincipalIdOrFallback(int $userId, int $requested): int
    {
        if ($this->authService->isAdmin()
            || $this->principalService->callerControlsPrincipal($userId, $requested)) {
            return $requested;
        }
        return (int) $this->principalService->ensureUserPrincipal($userId)->id;
    }

    /**
     * GET /api/v1/agents/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $agent = $this->agentService->getAgent($agentId, $userId);

        if ($agent === null) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['agent' => AgentResource::toArray($agent, $this->agentResourceContext($agent))]]);
    }

    /**
     * PATCH /api/v1/agents/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $bodyOrError = $this->decodeBodyOrError($request);
        if ($bodyOrError instanceof JsonResponse) {
            return $bodyOrError;
        }
        $body = $bodyOrError;

        $agent = $this->applyAgentPatch($agentId, $userId, $body);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        return new JsonResponse(['data' => ['agent' => AgentResource::toArray($agent, $this->agentResourceContext($agent))]]);
    }

    /**
     * Apply the agents-row write plus the optional profile_picture nested
     * object. Returns the updated Agent on success, or a JsonResponse (404
     * or 422) on failure. Extracted from `update()` so the controller stays
     * under the 3-return ceiling (S1142) and 15-cognitive-complexity ceiling
     * (S3776).
     *
     * The `profile_picture` payload is validated *before* the agents-row
     * write so an invalid picture never partially overwrites the name /
     * description / system_prompt. The two writes are kept in a single
     * service call so either both succeed or the agents row is unchanged.
     */
    private function applyAgentPatch(int $agentId, int $userId, array $body): Agent|JsonResponse
    {
        // Plan A: `is_favorite` is gone from this allowlist — the column
        // no longer exists on `agents`. The toggle is per-user via
        // `POST /agents/{id}/favorite` / `DELETE /agents/{id}/favorite`.
        $allowed = ['name', 'description', 'system_prompt', 'notes', 'llm_driver_config_id', 'max_steps', 'allow_followup', 'retry_after_minutes', 'max_retries', 'is_pinned', 'is_archived'];
        $data = array_intersect_key($body, array_flip($allowed));
        $this->coerceBooleanFlags($data);

        $picturePayload = $this->validateProfilePicturePayload($body);
        if ($picturePayload instanceof JsonResponse) {
            return $picturePayload;
        }

        $agent = $this->agentService->updateAgent($agentId, $userId, $data);
        if ($agent === null) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        $this->applyProfilePictureUpdate($agentId, $picturePayload);

        return $agent;
    }

    /**
     * Coerce the boolean-flag PATCH fields (is_pinned / is_archived /
     * is_favorite) to real bools. Booleans arrive as either real bools or
     * boolean-strings (the form layer + curl both send 'true'/'false').
     * FILTER_VALIDATE_BOOLEAN normalises both to a real bool regardless of
     * transport. notes stays a raw string (markdown) — never coerced.
     *
     * @param array<string, mixed> $data
     */
    private function coerceBooleanFlags(array &$data): void
    {
        // Plan A: `is_favorite` is no longer in this list — the column
        // was dropped in migration 0079 and the per-user pivot is set via
        // dedicated endpoints (not PATCH).
        foreach (['is_pinned', 'is_archived'] as $boolKey) {
            if (array_key_exists($boolKey, $data)) {
                $data[$boolKey] = filter_var($data[$boolKey], FILTER_VALIDATE_BOOLEAN);
            }
        }
    }

    /**
     * Validate the profile_picture nested payload (type + shape + enum
     * values). Returns the validated payload on success, or a 422
     * JsonResponse on the first failure. Empty array means "no
     * picture payload to apply". Delegates to
     * {@see \Spora\Services\ProfilePictures\ProfilePictureService::validatePayload()}
     * so the wire contract lives in one place — the same path runs
     * for {@see GroupController}.
     *
     * The `profile_picture` payload is validated *before* the agents-row
     * write so an invalid picture never partially overwrites the name /
     * description / system_prompt.
     *
     * @param  array<string, mixed> $body
     * @return array<string, string>|JsonResponse
     */
    private function validateProfilePicturePayload(array $body): array|JsonResponse
    {
        if (!array_key_exists('profile_picture', $body) || $this->pictureService === null) {
            return [];
        }
        $validated = $this->pictureService->validatePayload($body['profile_picture']);
        if ($validated instanceof \Spora\Services\ProfilePictures\ProfilePictureValidationError) {
            return $this->unprocessable($validated->code, $validated->message);
        }
        /** @var array<string, string> $validated */
        return $validated;
    }

    /**
     * Write the profile_picture nested object (archetype / variant_key /
     * palette_key) when the payload is a well-formed object. No-op for
     * missing / null / scalar / list payloads — the validator in
     * {@see applyAgentPatch()} ensures we never reach this helper
     * with anything else. Both writes share the agents table update,
     * so a throw on the picture path surfaces a 5xx and the operator
     * can re-issue the PATCH.
     *
     * @param array<string, string> $picture
     */
    private function applyProfilePictureUpdate(int $agentId, array $picture): void
    {
        if ($picture === [] || $this->pictureService === null) {
            return;
        }
        $this->pictureService->updateAvatar(
            $agentId,
            isset($picture['archetype']) ? (string) $picture['archetype'] : null,
            isset($picture['variant_key']) ? (string) $picture['variant_key'] : null,
            isset($picture['palette_key']) ? (string) $picture['palette_key'] : null,
        );
    }

    /**
     * Decode the JSON body, returning a 400 JsonResponse on parse failure.
     * Extracted from `update()` so the controller body stays under the
     * cognitive-complexity ceiling (S1142's 3-return limit).
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBodyOrError(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * DELETE /api/v1/agents/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $deleted = $this->agentService->deleteAgent($agentId, $userId);

        if (!$deleted) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * POST /api/v1/agents/{id}/favorite — mark the agent as a favourite
     * for the calling user. Per-user; idempotent.
     */
    public function favorite(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        try {
            $this->favoriteService->setFavorite($userId, $agentId);
        } catch (AgentNotFoundException) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['is_favorite' => true]]);
    }

    /**
     * DELETE /api/v1/agents/{id}/favorite — drop the favourite for the
     * calling user. No-op if no row exists.
     */
    public function unfavorite(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        try {
            $this->favoriteService->unsetFavorite($userId, $agentId);
        } catch (AgentNotFoundException) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['is_favorite' => false]]);
    }

    private function resolveSupportsImageInput(Agent $agent): bool
    {
        if ($this->driverFactory === null) {
            return false;
        }
        try {
            $driver = $this->driverFactory->makeFromAgent($agent);
        } catch (Throwable) {
            return false;
        }
        return $driver->supportsImageInput();
    }

    private function agentResourceContext(Agent $agent): AgentResourceContext
    {
        return new AgentResourceContext(
            supportsImageInput: $this->resolveSupportsImageInput($agent),
            iconResolver: $this->toolIconResolver,
            pictureService: $this->pictureService,
        );
    }
}
