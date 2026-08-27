<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\GroupLlmConfigsController;
use Spora\Services\LlmConfigSchemaValidator;
use Spora\Services\LLMConfigService;
use Spora\Services\LlmConfigValidator;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

defined('GLC_TEST_PASSWORD') || define('GLC_TEST_PASSWORD', 'Password1!');

function makeGroupLlmConfigsController(): array
{
    $auth = bootAuthLayer();
    $principalService = new PrincipalService(new PrincipalResolver());
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmConfigService = new LLMConfigService(
        $security,
        [OpenAICompatibleDriver::class],
        null,
        null,
        null,
        new PrincipalResolver(),
        $principalService,
    );
    $validator = new LlmConfigValidator($llmConfigService, new LlmConfigSchemaValidator(), $principalService, new PrincipalResolver());
    $controller = new GroupLlmConfigsController($auth, $llmConfigService, $validator, $principalService);

    return [$controller, $auth, $principalService, $llmConfigService, $validator];
}

describe('GroupLlmConfigsController', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('returns 401 when no user is logged in for index', function (): void {
        [$controller] = makeGroupLlmConfigsController();
        $response = $controller->index(1);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for store', function (): void {
        [$controller] = makeGroupLlmConfigsController();
        $response = $controller->store(1, jsonRequest('POST', '/x', []));
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for update', function (): void {
        [$controller] = makeGroupLlmConfigsController();
        $response = $controller->update(1, 1, jsonRequest('PATCH', '/x', ['name' => 'n']));
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for destroy', function (): void {
        [$controller] = makeGroupLlmConfigsController();
        $response = $controller->destroy(1, 1);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for setDefault', function (): void {
        [$controller] = makeGroupLlmConfigsController();
        $response = $controller->setDefault(1, 1);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 200 with empty configs list when none exist', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1a-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg1');
        simulateLoggedInSession($ownerId, 'glc1a-owner@example.com');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['configs'])->toBe([]);
    });

    it('returns 200 with configs scoped to the group principal (no global leak)', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1b-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg2');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;

        // Insert a group-scoped row and a global row via raw INSERT.
        Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insert([
            'principal_id' => $principalId,
            'name'         => 'Scoped',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => '{}',
            'is_default'   => false,
            'is_global'    => false,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insert([
            'principal_id' => null,
            'name'         => 'Global',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => '{}',
            'is_default'   => false,
            'is_global'    => true,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        simulateLoggedInSession($ownerId, 'glc1b-owner@example.com');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['configs'])->toHaveCount(1);
        expect($body['data']['configs'][0]['name'])->toBe('Scoped');
    });

    it('returns 201 on create', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1c-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg3');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        simulateLoggedInSession($ownerId, 'glc1c-owner@example.com');

        $response = $controller->store($group->id, jsonRequest('POST', '/x', [
            'name'         => 'New',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => ['api_key' => 'sk-test'],
        ]));
        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['config']['name'])->toBe('New');
        expect((int) $body['data']['config']['principal_id'])->toBe($principalId);
        expect($body['data']['config']['is_global'])->toBeFalse();
    });

    it('returns 200 on update', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1d-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg4');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;

        $configId = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insertGetId([
            'principal_id' => $principalId,
            'name'         => 'Original',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => '{}',
            'is_default'   => false,
            'is_global'    => false,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        simulateLoggedInSession($ownerId, 'glc1d-owner@example.com');
        $response = $controller->update($group->id, $configId, jsonRequest('PATCH', '/x', ['name' => 'Renamed']));
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['config']['name'])->toBe('Renamed');
    });

    it('returns 200 on delete', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1e-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg5');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $configId = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insertGetId([
            'principal_id' => $principalId,
            'name'         => 'ToDelete',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => '{}',
            'is_default'   => false,
            'is_global'    => false,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        simulateLoggedInSession($ownerId, 'glc1e-owner@example.com');

        $response = $controller->destroy($group->id, $configId);
        expect($response->getStatusCode())->toBe(200);

        $count = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')
            ->where('id', $configId)
            ->count();
        expect($count)->toBe(0);
    });

    it('returns 200 on setDefault and only one row stays default for the group', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1f-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg6');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $first = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insertGetId([
            'principal_id' => $principalId,
            'name'         => 'A',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => '{}',
            'is_default'   => true,
            'is_global'    => false,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $second = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insertGetId([
            'principal_id' => $principalId,
            'name'         => 'B',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => '{}',
            'is_default'   => false,
            'is_global'    => false,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        simulateLoggedInSession($ownerId, 'glc1f-owner@example.com');

        $response = $controller->setDefault($group->id, $second);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['config']['is_default'])->toBeTrue();

        $defaults = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')
            ->where('principal_id', $principalId)
            ->where('is_default', true)
            ->count();
        expect($defaults)->toBe(1);

        $firstStillDefault = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')
            ->where('id', $first)
            ->value('is_default');
        expect($firstStillDefault)->toBe(0);
    });

    it('returns 403 when caller is member-only on store', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1g-owner@example.com', GLC_TEST_PASSWORD);
        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'LlmCfg7');
        $memberId = bootAuth($auth, 'glc1g-member@example.com', GLC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, $memberId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
        simulateLoggedInSession($memberId, 'glc1g-member@example.com');

        $response = $controller->store($group->id, jsonRequest('POST', '/x', [
            'name'         => 'New',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => ['api_key' => 'sk-test'],
        ]));
        expect($response->getStatusCode())->toBe(403);
    });

    it('returns 200 for member-only on index', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1h-owner@example.com', GLC_TEST_PASSWORD);
        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'LlmCfg8');
        $memberId = bootAuth($auth, 'glc1h-member@example.com', GLC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, $memberId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
        simulateLoggedInSession($memberId, 'glc1h-member@example.com');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
    });

    it('returns 404 when group does not exist', function (): void {
        [$controller, $auth] = makeGroupLlmConfigsController();
        $uid = bootAuth($auth, 'glc1i@example.com', GLC_TEST_PASSWORD);
        simulateLoggedInSession($uid, 'glc1i@example.com');
        $response = $controller->index(999_999);
        expect($response->getStatusCode())->toBe(404);
    });

    it('returns 404 when caller is not a group member', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1j-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg9');
        $strangerId = bootAuth($auth, 'glc1j-stranger@example.com', GLC_TEST_PASSWORD);
        simulateLoggedInSession($strangerId, 'glc1j-stranger@example.com');
        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(404);
    });

    it('returns 404 on update when cid is not scoped to this group principal', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1k-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg10');
        $userPrincipalId = (int) $principalService->ensureUserPrincipal($ownerId)->id;
        $foreignId = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insertGetId([
            'principal_id' => $userPrincipalId,
            'name'         => 'Foreign',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => '{}',
            'is_default'   => false,
            'is_global'    => false,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        simulateLoggedInSession($ownerId, 'glc1k-owner@example.com');
        $response = $controller->update($group->id, $foreignId, jsonRequest('PATCH', '/x', ['name' => 'X']));
        expect($response->getStatusCode())->toBe(404);
    });

    it('writes under the GROUP principal, not the caller user-principal', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc1l-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfg11');
        $groupPrincipalId = (int) $principalService->principalForGroup($group->id)->id;
        simulateLoggedInSession($ownerId, 'glc1l-owner@example.com');

        $response = $controller->store($group->id, jsonRequest('POST', '/x', [
            'name'         => 'PrincipalTest',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => ['api_key' => 'sk-test'],
        ]));
        expect($response->getStatusCode())->toBe(201);

        $row = Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')
            ->where('name', 'PrincipalTest')
            ->first();
        expect((int) $row->principal_id)->toBe($groupPrincipalId);
        expect((int) $row->principal_id)->not->toBe((int) $principalService->ensureUserPrincipal($ownerId)->id);
    });
});
