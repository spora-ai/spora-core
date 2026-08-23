<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentPrincipalServiceInterface;
use Spora\Services\AgentResource;
use Spora\Services\AgentResourceContext;
use Spora\Services\Exceptions\UnauthorizedTransferException;
use Spora\Services\ToolIconResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Agent ownership-transfer endpoint.
 *
 * Routes:
 *   POST /api/v1/agents/{id}/transfer
 *
 * Split out of {@see AgentController} so the umbrella stays under
 * SonarCloud's 20-method-per-class ceiling (S1448). The transfer flow
 * is a self-contained vertical: it reads `principal_id` from the
 * body, gates on caller authorisation, and re-keys the agent's
 * `principal_id` via {@see AgentPrincipalService::transferAgent()}.
 *
 * Authorisation is enforced inside the service: the caller must control
 * both source and target principal (admin/owner of source, admin/owner
 * of the target, or owner of the target when the target is the
 * caller's user-principal). Admins skip the source side of the gate.
 */
final class AgentTransferController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentPrincipalServiceInterface $principalService,
        private readonly ?DriverFactory $driverFactory = null,
        private readonly ?ToolIconResolver $toolIconResolver = null,
        private readonly ?AgentPictureService $pictureService = null,
    ) {}

    /**
     * POST /api/v1/agents/{id}/transfer
     *
     * Body: { "principal_id": int }
     *
     * The 409 conflict response is reserved for
     * `PrincipalHasDependentsException`; here, the only failure modes
     * are 403 (caller does not control source/target) and 404 (agent
     * or target principal not found).
     */
    public function transferPrincipal(int $agentId, Request $request): JsonResponse
    {
        $setup = $this->resolveTransferSetup($request);
        if ($setup instanceof JsonResponse) {
            return $setup;
        }
        [$targetPrincipalId, $callerUserId] = $setup;

        $transferResult = $this->runTransfer($agentId, $targetPrincipalId, $callerUserId);
        if ($transferResult instanceof JsonResponse) {
            return $transferResult;
        }
        $agent = $transferResult;

        return new JsonResponse([
            'data' => [
                'agent' => AgentResource::toArray($agent, new AgentResourceContext(
                    supportsImageInput: $this->resolveSupportsImageInput($agent),
                    iconResolver: $this->toolIconResolver,
                    pictureService: $this->pictureService,
                )),
            ],
        ]);
    }

    /**
     * @return array{0: int, 1: int}|JsonResponse
     */
    private function resolveTransferSetup(Request $request): array|JsonResponse
    {
        $body = $this->decodeTransferBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $targetPrincipalId = $this->parseTargetPrincipalId($body);
        if ($targetPrincipalId instanceof JsonResponse) {
            return $targetPrincipalId;
        }

        return $this->resolveTransferSetupWithTarget($targetPrincipalId);
    }

    /**
     * @return array{0: int, 1: int}|JsonResponse
     */
    private function resolveTransferSetupWithTarget(int $targetPrincipalId): array|JsonResponse
    {
        $callerUserId = $this->authService->currentUserId();
        if ($callerUserId === null) {
            return $this->unauthenticated();
        }
        return [$targetPrincipalId, $callerUserId];
    }

    /**
     * @param  array<string, mixed> $body
     * @return int|JsonResponse
     */
    private function parseTargetPrincipalId(array $body): int|JsonResponse
    {
        $targetPrincipalId = (int) ($body['principal_id'] ?? 0);
        if ($targetPrincipalId <= 0) {
            return $this->error('VALIDATION_ERROR', 'principal_id is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return $targetPrincipalId;
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeTransferBody(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @return Agent|JsonResponse
     */
    private function runTransfer(int $agentId, int $targetPrincipalId, int $callerUserId): Agent|JsonResponse
    {
        try {
            return $this->principalService->transferAgent($agentId, $targetPrincipalId, $callerUserId);
        } catch (UnauthorizedTransferException $e) {
            return $this->forbidden('FORBIDDEN', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }
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
}
