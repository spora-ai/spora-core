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
    $controller = new AgentTransferController($authService, $principalService);

    return [$controller, $authService, $principalService];
}

describe('AgentTransferController::transferPrincipal', function (): void {
    test('returns 422 when principal_id is missing', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], '');
        $response = $controller->transferPrincipal(1, $request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when principal_id is zero', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], ['principal_id' => '0'], [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'principal_id=0');
        $response = $controller->transferPrincipal(1, $request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 401 when caller is not logged in', function (): void {
        [$controller] = makeAgentTransferController();

        $request = new Request([], ['principal_id' => '7'], [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'principal_id=7');
        $response = $controller->transferPrincipal(1, $request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    test('returns 404 when the agent does not exist', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], ['principal_id' => '7'], [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'principal_id=7');
        $response = $controller->transferPrincipal(999999, $request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('returns 200 with the transferred agent', function (): void {
        [$controller, $authService] = makeAgentTransferController();
        bootAuth($authService);

        $request = new Request([], ['principal_id' => '7'], [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'principal_id=7');
        $response = $controller->transferPrincipal(1, $request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agent']['id'])->toBe(1);
    });
});
