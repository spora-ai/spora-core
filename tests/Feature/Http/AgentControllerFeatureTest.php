<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Http\AgentController;
use Spora\Models\Agent;
use Spora\Services\AgentPrincipalService;
use Spora\Services\AgentService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

/**
 * `?principal_id=` contract for `AgentController::index`. The `?select=id,name`
 * picker branch and the full-payload branch both intersect the requested
 * principal(s) with the caller's `visiblePrincipalIds` — a foreign principal
 * never widens the result.
 *
 * The unit-level `tests/Unit/Http/AgentControllerTest.php` uses
 * `StubAgentService` which doesn't honour the filter, so the principal-id
 * contract is pinned here against a real `AgentService` + real DB.
 */
function buildIndexAgentController(): array
{
    $authService = bootAuthLayer();
    $principalResolver = new PrincipalResolver();
    $principalService  = new PrincipalService($principalResolver);
    $agentService = new AgentService(
        null,
        null,
        $principalResolver,
        new AgentPrincipalService($principalService),
    );

    $controller = new AgentController(
        $authService,
        $agentService,
        new \Spora\Services\AgentFavoriteService($agentService),
        null,
        null,
        null,
        $principalService,
        $principalResolver,
    );

    return [$controller, $authService, $principalService, $agentService];
}

function seedAgentRow(int $id, int $principalId, string $name): void
{
    $now = date('Y-m-d H:i:s');
    \Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
        ['id' => $id],
        [
            'principal_id' => $principalId,
            'name'         => $name,
            'max_steps'    => 10,
            'is_active'    => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    );
}

describe('AgentController::index ?principal_id= scoping', function (): void {

    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('with no ?principal_id= returns every visible agent (regression)', function (): void {
        [$controller, $authService, $principalService] = buildIndexAgentController();
        $userId = bootAuth($authService, 'idx-no-filter@example.com');
        $userPrincipal = (int) $principalService->ensureUserPrincipal($userId)->id;

        seedAgentRow(10, $userPrincipal, 'A1');
        seedAgentRow(11, $userPrincipal, 'A2');

        $response = $controller->index(Request::create('/api/v1/agents', 'GET'));
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agents'])->toHaveCount(2);
    });

    it('with ?principal_id=<own user-principal> returns only that principal\'s agents', function (): void {
        [$controller, $authService, $principalService] = buildIndexAgentController();
        $userId = bootAuth($authService, 'idx-own@example.com');
        $userPrincipal = (int) $principalService->ensureUserPrincipal($userId)->id;

        seedAgentRow(20, $userPrincipal, 'Own A');
        seedAgentRow(21, $userPrincipal, 'Own B');

        $otherAuth = bootAuthLayer();
        $otherUserId = $otherAuth->register('idx-other@example.com', 'Password1!', 'Other');
        $otherPrincipal = (int) $principalService->ensureUserPrincipal($otherUserId)->id;
        seedAgentRow(30, $otherPrincipal, 'Foreign');

        $response = $controller->index(
            Request::create('/api/v1/agents?principal_id=' . $userPrincipal, 'GET'),
        );
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agents'])->toHaveCount(2);
        foreach ($body['data']['agents'] as $agent) {
            expect((int) $agent['principal_id'])->toBe($userPrincipal);
        }
    });

    it('with ?principal_id=<foreign principal> returns an empty list (no widening)', function (): void {
        [$controller, $authService, $principalService] = buildIndexAgentController();
        $userId = bootAuth($authService, 'idx-foreign@example.com');
        $userPrincipal = (int) $principalService->ensureUserPrincipal($userId)->id;
        seedAgentRow(40, $userPrincipal, 'Mine');

        $otherAuth = bootAuthLayer();
        $otherUserId = $otherAuth->register('idx-foreign-other@example.com', 'Password1!', 'ForeignOther');
        $foreignPrincipal = (int) $principalService->ensureUserPrincipal($otherUserId)->id;
        seedAgentRow(50, $foreignPrincipal, 'Theirs');

        $response = $controller->index(
            Request::create('/api/v1/agents?principal_id=' . $foreignPrincipal, 'GET'),
        );
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agents'])->toBe([]);
    });

    it('with ?select=id,name&principal_id=<own> returns only id and name columns', function (): void {
        // Locks the contract for the multi-select picker path the Handover
        // tool uses: when a per-agent / per-group picker is rendered, the
        // ?principal_id= must both scope the rows AND continue to honour
        // the SELECTABLE_COLUMNS allowlist.
        [$controller, $authService, $principalService] = buildIndexAgentController();
        $userId = bootAuth($authService, 'idx-select@example.com');
        $userPrincipal = (int) $principalService->ensureUserPrincipal($userId)->id;
        seedAgentRow(60, $userPrincipal, 'Picker A');
        seedAgentRow(61, $userPrincipal, 'Picker B');

        $response = $controller->index(
            Request::create('/api/v1/agents?select=id,name&principal_id=' . $userPrincipal, 'GET'),
        );
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agents'])->toHaveCount(2);
        foreach ($body['data']['agents'] as $agent) {
            expect($agent)->toHaveKeys(['id', 'name']);
            expect($agent)->not->toHaveKey('principal_id');
            expect($agent)->not->toHaveKey('system_prompt');
        }
    });
});
