<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Spora\Http\AgentTransferController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array{AgentTransferController, \Spora\Auth\AuthService, StubAgentPrincipalService}
 */
function makeAgentTransferController(): array
{
    $authService = bootAuthLayer();
    $principalService = new StubAgentPrincipalService();
    // Plan A: the transfer response hydrates the per-viewer `is_favorite`
    // field, so the controller needs a real AgentFavoriteService. The
    // visibility check inside it routes through the AgentService stub
    // which returns a non-null Agent for any non-999999 id.
    $agentService = new \Spora\Services\AgentService();
    $favoriteService = new \Spora\Services\AgentFavoriteService($agentService);
    $controller = new AgentTransferController(
        $authService,
        $principalService,
        $favoriteService,
    );

    return [$controller, $authService, $principalService, $favoriteService];
}

describe('AgentTransferController::transferPrincipal', function (): void {
    test('returns 422 when principal_id is missing', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], [], ['id' => 1], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $response = $controller->transferPrincipal($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when principal_id is zero', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], [], ['id' => 1], [], [], ['CONTENT_TYPE' => 'application/json'], '{"principal_id": 0}');
        $response = $controller->transferPrincipal($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 401 when caller is not logged in', function (): void {
        [$controller] = makeAgentTransferController();

        $request = new Request([], [], ['id' => 1], [], [], ['CONTENT_TYPE' => 'application/json'], '{"principal_id": 7}');
        $response = $controller->transferPrincipal($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    test('returns 404 when the agent does not exist', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], [], ['id' => 999999], [], [], ['CONTENT_TYPE' => 'application/json'], '{"principal_id": 7}');
        $response = $controller->transferPrincipal($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('returns 200 with the transferred agent', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], [], ['id' => 1], [], [], ['CONTENT_TYPE' => 'application/json'], '{"principal_id": 7}');
        $response = $controller->transferPrincipal($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agent']['id'])->toBe(1);
    });

    test('returns 400 when the body is not JSON', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], [], ['id' => 1], [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json');
        $response = $controller->transferPrincipal($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });
});
