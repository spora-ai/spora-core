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

    it('returns 404 for a global admin who is not a member of the group', function (): void {
        [$controller, $auth, $principalService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc-admin-stranger-owner@example.com', GLC_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'LlmCfgAdminBlock');
        $adminId = bootAuth($auth, 'glc-admin-stranger@example.com', GLC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);
        simulateLoggedInSession($adminId, 'glc-admin-stranger@example.com');

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

    // Regression for Issue #6: destroying a group-scoped LLM config must
    // call LLMConfigPersistence::detachConfigurationReferences() so any
    // agent.llm_driver_config_id or principal_preferences.preferred_llm_config_id
    // pointing at the doomed row is nulled before the row vanishes —
    // otherwise the FK on the agent column violates its NOT NULL on the next
    // read once the agent FK is enforced, and the preference row leaves a
    // dangling pointer. The setDefault branch on its own is exercised in
    // LLMConfigPreferencesTest; the destroy branch is here because it had
    // no test before this PR.
    it('detach: destroy() nulls an agent and preference that pointed at the doomed row', function (): void {
        [$controller, $auth, $principalService, $llmConfigService] = makeGroupLlmConfigsController();
        $ownerId = bootAuth($auth, 'glc-detach-owner@example.com', GLC_TEST_PASSWORD);
        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'LlmCfgDetach');
        $groupPrincipalId = (int) $principalService->principalForGroup($group->id)->id;

        $configId = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insertGetId([
            'principal_id' => $groupPrincipalId,
            'name'         => 'Doomed',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => '{}',
            'is_default'   => false,
            'is_global'    => false,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $agentId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => $groupPrincipalId,
            'name'         => 'A',
            'llm_driver_config_id' => $configId,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
            'principal_id' => $groupPrincipalId,
            'preferred_llm_config_id' => $configId,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        simulateLoggedInSession($ownerId, 'glc-detach-owner@example.com');
        $response = $controller->destroy($group->id, $configId);

        expect($response->getStatusCode())->toBe(200);
        expect(Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->where('id', $configId)->count())->toBe(0);
        expect((int) Illuminate\Database\Capsule\Manager::table('agents')->where('id', $agentId)->value('llm_driver_config_id'))->toBe(0);
        expect(Illuminate\Database\Capsule\Manager::table('principal_preferences')->where('preferred_llm_config_id', $configId)->count())->toBe(0);
    });
});

describe('LLMConfigPreferences race regression (Issue #5)', function (): void {
    // Regression guard: LLMConfigPreferences::setDefaultConfiguration
    // used to perform {clear-existing-default → save-new} outside any
    // transaction and without `lockForUpdate`, so two parallel admins
    // could each observe the prior default row, both clear it, and
    // both promote their own target — leaving either two defaults
    // (corruption) or zero defaults (silent outage) depending on the
    // commit order. The fix wraps the operation in a transaction with
    // a `lockForUpdate` lock on the prior default row. This file
    // exercises the contract by running the new code path on top of a
    // pre-existing default + a fresh candidate; the second call must
    // finish with exactly one default and the new one winning — which
    // is what the lock now guarantees.
    it('two sequential setDefault calls converge on the latest target — never zero, never two', function (): void {
        $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $first = new Spora\Models\LLMDriverConfiguration();
        $first->principal_id = null;
        $first->name = 'First';
        $first->driver_class = OpenAICompatibleDriver::class;
        $first->settings = json_encode([]);
        $first->is_global = true;
        $first->is_default = true;
        $first->save();

        $second = new Spora\Models\LLMDriverConfiguration();
        $second->principal_id = null;
        $second->name = 'Second';
        $second->driver_class = OpenAICompatibleDriver::class;
        $second->settings = json_encode([]);
        $second->is_global = true;
        $second->is_default = false;
        $second->save();

        $r1 = $service->setDefaultConfiguration((int) $first->getKey(), 1, true);
        $r2 = $service->setDefaultConfiguration((int) $second->getKey(), 1, true);

        expect($r1)->not->toBeNull()
            ->and($r2)->not->toBeNull();

        $defaults = Spora\Models\LLMDriverConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->pluck('id')
            ->all();
        expect($defaults)->toBe([(int) $second->getKey()]);
    });

    it('setDefaultConfiguration returns null for non-admin callers and does not flip any row', function (): void {
        $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $global = new Spora\Models\LLMDriverConfiguration();
        $global->principal_id = null;
        $global->name = 'Locked';
        $global->driver_class = OpenAICompatibleDriver::class;
        $global->settings = json_encode([]);
        $global->is_global = true;
        $global->is_default = false;
        $global->save();

        $result = $service->setDefaultConfiguration((int) $global->getKey(), 99, false);
        expect($result)->toBeNull();

        $defaults = Spora\Models\LLMDriverConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->count();
        expect($defaults)->toBe(0);
    });
});
