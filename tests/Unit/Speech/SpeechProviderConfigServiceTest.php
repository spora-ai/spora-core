<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Models\SpeechProviderConfiguration;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\SpeechProviderConfigPersistence;
use Spora\Services\SpeechProviderConfigPreferences;
use Spora\Services\SpeechProviderConfigService;
use Spora\Services\SpeechProviderConfigValidator;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextRegistry;

defined('SPC_TEST_PASSWORD') || define('SPC_TEST_PASSWORD', 'Password1!');

/**
 * Build a {@see SpeechProviderConfigService} wired against the real
 * SecurityManager (so the encode/decode round-trip matches
 * production). Returns the service + collaborator handles so callers
 * can re-stub.
 */
function makeSpeechConfigService(): SpeechProviderConfigService
{
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $principalService = new PrincipalService(new PrincipalResolver());
    $registry = new SpeechToTextRegistry([new OpenAiCompatibleTranscriber(
        new Symfony\Component\HttpClient\MockHttpClient(),
        new Spora\Services\ToolConfigService($security, new Psr\Log\NullLogger(), []),
    )], $principalService);
    $validator = new SpeechProviderConfigValidator($registry);
    $persistence = new SpeechProviderConfigPersistence($security, $validator, static fn(): SpeechToTextRegistry => $registry);
    $preferences = new SpeechProviderConfigPreferences($principalService);
    return new SpeechProviderConfigService($validator, $persistence, $preferences, $principalService);
}

function clearSpeechPreferences(): void
{
    Capsule::table('principal_preferences')->delete();
    Capsule::table('speech_provider_configurations')->delete();
}

function bootAdmin(int $userId, AuthService $auth): int
{
    $auth->grantRole($userId, Delight\Auth\Role::ADMIN);
    return $userId;
}

describe('SpeechProviderConfigService', function (): void {
    beforeEach(function () {
        clearSession();
        clearSpeechPreferences();
    });

    afterEach(function () {
        clearSession();
        clearSpeechPreferences();
    });

    it('admin creates a global config and lists it back', function (): void {
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'spc-admin-crud@example.com', SPC_TEST_PASSWORD);
        bootAdmin($userId, $auth);

        $service = makeSpeechConfigService();

        $created = $service->createConfiguration($userId, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'display_name' => 'Global',
            'settings' => [
                'api_key' => 'sk-global',
                'display_name' => 'Global',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);

        expect($created)->not->toBeNull();
        expect($created->is_global)->toBeTrue();
        expect($created->provider_class)->toBe(OpenAiCompatibleTranscriber::class);

        $rows = $service->getConfigurationsForUser($userId);
        expect($rows)->toHaveCount(1);
        expect($rows[0]['id'])->toBe((int) $created->id);
        expect($rows[0]['settings']['api_key'])->toBe('***');
    });

    it('non-admin cannot create a global config', function (): void {
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'spc-nonadmin-crud@example.com', SPC_TEST_PASSWORD);

        $service = makeSpeechConfigService();

        $threw = false;
        try {
            $service->createConfiguration($userId, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => true,
                'settings' => [],
            ], isAdmin: false);
        } catch (Spora\Http\Exceptions\SpeechProviderConfigException $e) {
            $threw = $e->statusCode === 403;
        }

        expect($threw)->toBeTrue();
    });

    it('non-admin can create a per-user config under their own user-principal', function (): void {
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'spc-user-crud@example.com', SPC_TEST_PASSWORD);

        $service = makeSpeechConfigService();

        $created = $service->createConfiguration($userId, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => false,
            'settings' => [
                'api_key' => 'sk-user',
                'display_name' => 'Personal',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: false);

        expect($created)->not->toBeNull();
        expect($created->is_global)->toBeFalse();
        expect($created->principal_id)->not->toBeNull();
    });

    it('returns only globals (no per-principal leak) for a non-admin caller with no visible principals', function (): void {
        // Walks the `whereRaw(self::NO_MATCH)` branch in
        // getConfigurationsForUser(); sibling tests all materialise a
        // user-principal and take the `whereIn` path.
        $auth = bootAuthLayer();
        $admin = bootAuth($auth, 'spc-get-user-admin@example.com', SPC_TEST_PASSWORD);
        bootAdmin($admin, $auth);
        $isolated = bootAuth($auth, 'spc-get-user-isolated@example.com', SPC_TEST_PASSWORD);
        // Note: no createUserPrincipalPublic($isolated); visiblePrincipalIds()
        // must resolve to [] to exercise the empty-principals branch.

        $service = makeSpeechConfigService();
        $global = $service->createConfiguration($admin, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => [
                'api_key' => 'sk-g',
                'display_name' => 'Global',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);
        $foreign = $service->createConfiguration($admin, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => false,
            'display_name' => 'Admin-owned',
            'settings' => [
                'api_key' => 'sk-a',
                'display_name' => 'Admin-owned',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);

        $rows = $service->getConfigurationsForUser($isolated);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);

        expect($ids)->toBe([(int) $global->id])
            ->and($ids)->not->toContain((int) $foreign->id);
    });

    it('update merges existing settings into the request so omitted-and-kept values survive', function (): void {
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'spc-update-merge@example.com', SPC_TEST_PASSWORD);
        bootAdmin($userId, $auth);

        $service = makeSpeechConfigService();

        $created = $service->createConfiguration($userId, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'display_name' => 'Original',
            'settings' => [
                'api_key' => 'sk-kept',
                'display_name' => 'Original',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);

        $updated = $service->updateConfiguration((int) $created->id, $userId, [
            'display_name' => 'Renamed',
            'settings' => [
                'api_key' => 'sk-kept',
                'display_name' => 'Renamed',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);

        expect($updated)->not->toBeNull();
        expect($updated->display_name)->toBe('Renamed');
    });

    it('delete detaches both an agent FK and a preference FK (regression for Issue #6 LLM analogue)', function (): void {
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'spc-detach@example.com', SPC_TEST_PASSWORD);
        bootAdmin($userId, $auth);

        $service = makeSpeechConfigService();

        $config = $service->createConfiguration($userId, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => [
                'api_key' => 'sk-detach',
                'display_name' => 'Detach',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);

        $agentId = (int) Capsule::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($userId),
            'name' => 'A',
            'speech_driver_config_id' => $config->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $userPrincipalId = createUserPrincipalPublic($userId);
        Capsule::table('principal_preferences')->insert([
            'principal_id' => $userPrincipalId,
            'preferred_speech_config_id' => $config->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $deleted = $service->deleteConfiguration((int) $config->id, $userId, isAdmin: true);
        expect($deleted)->toBeTrue();
        expect(SpeechProviderConfiguration::find($config->id))->toBeNull();
        expect((int) Capsule::table('agents')->where('id', $agentId)->value('speech_driver_config_id'))->toBe(0);
        expect(Capsule::table('principal_preferences')->where('preferred_speech_config_id', $config->id)->count())->toBe(0);
    });

    it('set-default flips another row off on subsequent calls (race-guarded, second call never leaves two defaults)', function (): void {
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'spc-set-default@example.com', SPC_TEST_PASSWORD);
        bootAdmin($userId, $auth);

        $service = makeSpeechConfigService();

        $first = $service->createConfiguration($userId, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'display_name' => 'First',
            'settings' => [
                'api_key' => 'sk-1',
                'display_name' => 'First',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);

        $service->createConfiguration($userId, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'display_name' => 'Second',
            'settings' => [
                'api_key' => 'sk-2',
                'display_name' => 'Second',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);

        $r1 = $service->setDefaultConfiguration((int) $first->id, $userId, isAdmin: true);
        expect($r1)->not->toBeNull()->and($r1->is_default)->toBeTrue();

        $secondId = (int) SpeechProviderConfiguration::where('is_default', false)->value('id');
        $r2 = $service->setDefaultConfiguration($secondId, $userId, isAdmin: true);
        expect($r2)->not->toBeNull()->and($r2->is_default)->toBeTrue();

        $defaults = SpeechProviderConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->count();
        expect($defaults)->toBe(1);
    });

    it('preferred config: set / get / clear', function (): void {
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'spc-pref@example.com', SPC_TEST_PASSWORD);
        bootAdmin($userId, $auth);

        $service = makeSpeechConfigService();

        $created = $service->createConfiguration($userId, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => [
                'api_key' => 'sk',
                'display_name' => 'Pref',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ], isAdmin: true);

        $principalId = (int) (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($userId)->id;
        $ok = $service->setPrincipalPreferredConfig($principalId, (int) $created->id, $userId);
        expect($ok)->toBeTrue();

        $resolved = $service->resolvePreferredConfig($userId, isAdmin: true, scope: 'user');
        expect($resolved)->not->toBeNull()
            ->and((int) $resolved->id)->toBe((int) $created->id);

        $service->unsetPrincipalPreferredConfig($principalId);
        $resolved = $service->resolvePreferredConfig($userId, isAdmin: true, scope: 'user');
        expect($resolved)->toBeNull();
    });

    describe('getConfigurationsForAgent', function (): void {
        // Dropdown is scoped to the agent's own principal — never to
        // the caller's `visiblePrincipalIds()`. Mirrors
        // {@see LLMConfigService::getConfigurationsForAgent()}.

        it('returns the principal-scoped configs plus every global config (user-owned agent)', function (): void {
            // A's user-principal config + global config; B's user-scope
            // config is hidden because it does not match the agent's principal.
            $auth = bootAuthLayer();
            $userA = bootAuth($auth, 'spc-scope-a@example.com', SPC_TEST_PASSWORD);
            $userB = bootAuth($auth, 'spc-scope-b@example.com', SPC_TEST_PASSWORD);
            bootAdmin($userA, $auth);

            $service = makeSpeechConfigService();

            $global = $service->createConfiguration($userA, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => true,
                'settings' => [
                    'api_key' => 'sk-g',
                    'display_name' => 'Global',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: true);

            $userAPrincipalId = createUserPrincipalPublic($userA);
            $userBPrincipalId = createUserPrincipalPublic($userB);

            $userAConfig = $service->createConfiguration($userA, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => false,
                'display_name' => 'A-User',
                'settings' => [
                    'api_key' => 'sk-a',
                    'display_name' => 'A-User',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: false);

            // B's config must not leak into A's agent dropdown — the
            // dropdown's principal scope is the agent's principal,
            // not the caller's.
            $userBConfig = $service->createConfiguration($userB, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => false,
                'display_name' => 'B-User',
                'settings' => [
                    'api_key' => 'sk-b',
                    'display_name' => 'B-User',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: false);

            $agentA = (int) Capsule::table('agents')->insertGetId([
                'principal_id' => $userAPrincipalId,
                'name' => 'A',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $returned = $service->getConfigurationsForAgent($agentA);
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $returned);
            sort($ids);

            expect($ids)->toBe([(int) $global->id, (int) $userAConfig->id]);
        });

        it('returns only the group-scoped configs plus globals (group-owned agent; caller\'s user-scoped config is hidden)', function (): void {
            // Owner happens to belong to both principals, but their
            // user-scoped config must NOT appear in the group agent's
            // dropdown — the scope is the agent's principal, not the
            // caller's visible principals.
            $auth = bootAuthLayer();
            $ownerId = bootAuth($auth, 'spc-group-owner@example.com', SPC_TEST_PASSWORD);
            bootAdmin($ownerId, $auth);

            $service = makeSpeechConfigService();

            $global = $service->createConfiguration($ownerId, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => true,
                'settings' => [
                    'api_key' => 'sk-g',
                    'display_name' => 'Global',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: true);

            $userPrincipalId = createUserPrincipalPublic($ownerId);

            $groupId = (int) Capsule::table('groups')->insertGetId([
                'name' => 'spc-scope-group',
                'description' => null,
                'created_by_user_id' => $ownerId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $groupPrincipalId = (int) Capsule::table('principals')->insertGetId([
                'type'     => Principal::TYPE_GROUP,
                'group_id' => $groupId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Group-scoped config (owned by the group principal).
            $groupConfig = new SpeechProviderConfiguration();
            $groupConfig->principal_id = $groupPrincipalId;
            $groupConfig->provider_class = OpenAiCompatibleTranscriber::class;
            $groupConfig->settings = json_encode([
                'api_key' => 'sk-group',
                'display_name' => 'Group',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ]);
            $groupConfig->display_name = 'Group';
            $groupConfig->is_default = false;
            $groupConfig->is_global = false;
            $groupConfig->save();

            // User-scoped config — must NOT appear in the group agent's
            // dropdown even though the owner belongs to that principal.
            $userScoped = new SpeechProviderConfiguration();
            $userScoped->principal_id = $userPrincipalId;
            $userScoped->provider_class = OpenAiCompatibleTranscriber::class;
            $userScoped->settings = json_encode([
                'api_key' => 'sk-u',
                'display_name' => 'U',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ]);
            $userScoped->display_name = 'U';
            $userScoped->is_default = false;
            $userScoped->is_global = false;
            $userScoped->save();

            $groupAgentId = (int) Capsule::table('agents')->insertGetId([
                'principal_id' => $groupPrincipalId,
                'name' => 'G',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $returned = $service->getConfigurationsForAgent($groupAgentId);
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $returned);
            sort($ids);

            expect($ids)->toBe([(int) $global->id, (int) $groupConfig->id])
                ->and($ids)->not->toContain((int) $userScoped->id);
        });

        it('returns an empty list when the agent row does not exist', function (): void {
            $service = makeSpeechConfigService();
            expect($service->getConfigurationsForAgent(999_999_999))->toBe([]);
        });
    });

    describe('getConfigurationsForGroup', function (): void {
        it('returns only the requested group\'s configs plus every global config for an admin caller', function (): void {
            $auth = bootAuthLayer();
            $ownerId = bootAuth($auth, 'spc-grp-scope-owner@example.com', SPC_TEST_PASSWORD);
            $adminId = bootAdmin($ownerId, $auth);

            $service = makeSpeechConfigService();
            $principalService = new PrincipalService(new PrincipalResolver());
            $groupService = new GroupService($principalService);

            $groupA = $groupService->createGroup($adminId, 'SpGroupScopeA' . random_int(1, 999999));
            $groupB = $groupService->createGroup($adminId, 'SpGroupScopeB' . random_int(1, 999999));
            $groupAPrincipalId = (int) Principal::where('type', Principal::TYPE_GROUP)->where('group_id', $groupA->id)->value('id');
            $groupBPrincipalId = (int) Principal::where('type', Principal::TYPE_GROUP)->where('group_id', $groupB->id)->value('id');

            // Bypass the controller's scope translation and insert the
            // group-scope config rows directly via the model — service-level
            // tests don't exercise that translation (see getConfigurationsForAgent).
            $groupAConfig = new SpeechProviderConfiguration();
            $groupAConfig->principal_id = $groupAPrincipalId;
            $groupAConfig->provider_class = OpenAiCompatibleTranscriber::class;
            $groupAConfig->display_name = 'A';
            $groupAConfig->settings = json_encode(['api_key' => 'sk-a']);
            $groupAConfig->is_default = false;
            $groupAConfig->is_global = false;
            $groupAConfig->save();

            $groupBConfig = new SpeechProviderConfiguration();
            $groupBConfig->principal_id = $groupBPrincipalId;
            $groupBConfig->provider_class = OpenAiCompatibleTranscriber::class;
            $groupBConfig->display_name = 'B';
            $groupBConfig->settings = json_encode(['api_key' => 'sk-b']);
            $groupBConfig->is_default = false;
            $groupBConfig->is_global = false;
            $groupBConfig->save();

            $globalConfig = new SpeechProviderConfiguration();
            $globalConfig->principal_id = null;
            $globalConfig->provider_class = OpenAiCompatibleTranscriber::class;
            $globalConfig->display_name = 'Global';
            $globalConfig->settings = json_encode(['api_key' => 'sk-g']);
            $globalConfig->is_default = false;
            $globalConfig->is_global = true;
            $globalConfig->save();

            // Admin requests group A's page → must see A + globals, NOT B.
            $returned = $service->getConfigurationsForGroup($groupA->id, $adminId, true);
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $returned);
            sort($ids);

            expect($ids)->toBe([min((int) $globalConfig->id, (int) $groupAConfig->id), max((int) $globalConfig->id, (int) $groupAConfig->id)])
                ->and($ids)->not->toContain((int) $groupBConfig->id);
        });

        it('returns an empty list for a non-member caller (existence-hide for other groups)', function (): void {
            $auth = bootAuthLayer();
            $ownerId = bootAuth($auth, 'spc-grp-nonmember-owner@example.com', SPC_TEST_PASSWORD);
            $memberId = bootAuth($auth, 'spc-grp-nonmember@example.com', SPC_TEST_PASSWORD);
            $adminId = bootAdmin($ownerId, $auth);

            $service = makeSpeechConfigService();
            $principalService = new PrincipalService(new PrincipalResolver());
            $groupService = new GroupService($principalService);
            $group = $groupService->createGroup($adminId, 'SpPrivateGroup' . random_int(1, 999999));
            $groupPrincipalId = (int) Principal::where('type', Principal::TYPE_GROUP)->where('group_id', $group->id)->value('id');

            $groupConfig = new SpeechProviderConfiguration();
            $groupConfig->principal_id = $groupPrincipalId;
            $groupConfig->provider_class = OpenAiCompatibleTranscriber::class;
            $groupConfig->display_name = 'Private';
            $groupConfig->settings = json_encode(['api_key' => 'sk-p']);
            $groupConfig->is_default = false;
            $groupConfig->is_global = false;
            $groupConfig->save();

            // Non-admin, non-member caller → existence-hide. Without this
            // gate the controller would leak the group's configs into the
            // caller's dropdown on the group page.
            $returned = $service->getConfigurationsForGroup($group->id, $memberId, false);
            expect($returned)->toBe([]);
        });

        it('returns the configs for a member caller', function (): void {
            $auth = bootAuthLayer();
            $ownerId = bootAuth($auth, 'spc-grp-member-owner@example.com', SPC_TEST_PASSWORD);
            $memberId = bootAuth($auth, 'spc-grp-member@example.com', SPC_TEST_PASSWORD);
            $adminId = bootAdmin($ownerId, $auth);

            $service = makeSpeechConfigService();
            $principalService = new PrincipalService(new PrincipalResolver());
            $groupService = new GroupService($principalService);
            $group = $groupService->createGroup($adminId, 'SpMemberGroup' . random_int(1, 999999));
            $groupService->addMember($group->id, $memberId, GroupMembership::ROLE_MEMBER, $adminId);
            $groupPrincipalId = (int) Principal::where('type', Principal::TYPE_GROUP)->where('group_id', $group->id)->value('id');

            // Materialise $memberId's user-principal so `visiblePrincipalIds()`
            // doesn't short-circuit to `[]` (it returns `[]` if the user has
            // no principal row, even when they're a group member).
            createUserPrincipalPublic($memberId);

            $groupConfig = new SpeechProviderConfiguration();
            $groupConfig->principal_id = $groupPrincipalId;
            $groupConfig->provider_class = OpenAiCompatibleTranscriber::class;
            $groupConfig->display_name = 'MemberVisible';
            $groupConfig->settings = json_encode(['api_key' => 'sk-m']);
            $groupConfig->is_default = false;
            $groupConfig->is_global = false;
            $groupConfig->save();

            $returned = $service->getConfigurationsForGroup($group->id, $memberId, false);
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $returned);
            expect($ids)->toBe([(int) $groupConfig->id]);
        });

        it('returns an empty list when the group has no principal row (data integrity)', function (): void {
            $auth = bootAuthLayer();
            $ownerId = bootAuth($auth, 'spc-grp-no-principal-owner@example.com', SPC_TEST_PASSWORD);

            $service = makeSpeechConfigService();
            $principalService = new PrincipalService(new PrincipalResolver());
            $groupService = new GroupService($principalService);
            $group = $groupService->createGroup($ownerId, 'SpOrphanGroup' . random_int(1, 999999));

            // Simulate a rolled-back transaction that left a `groups` row
            // without a matching principal row. Existence-hide — never error.
            Capsule::table('principals')
                ->where('type', Principal::TYPE_GROUP)
                ->where('group_id', $group->id)
                ->delete();

            expect($service->getConfigurationsForGroup($group->id, $ownerId, true))->toBe([]);
        });
    });

    describe('getConfiguration', function (): void {
        // The four scenarios below exercise both halves of the
        // visibility disjunction (principal_id ∪ is_global) and the
        // admin short-circuit.

        it('returns the row when the non-admin caller owns the principal', function (): void {
            // Walks the principal_id branch of applyVisibleScope().
            $auth = bootAuthLayer();
            $userA = bootAuth($auth, 'spc-get-config-a1@example.com', SPC_TEST_PASSWORD);
            $userB = bootAuth($auth, 'spc-get-config-b1@example.com', SPC_TEST_PASSWORD);

            $service = makeSpeechConfigService();
            $userAConfig = $service->createConfiguration($userA, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => false,
                'display_name' => 'A',
                'settings' => [
                    'api_key' => 'sk-a',
                    'display_name' => 'A',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: false);
            createUserPrincipalPublic($userB);

            $found = $service->getConfiguration((int) $userAConfig->id, $userA, isAdmin: false);
            expect($found)->not->toBeNull()
                ->and((int) $found->id)->toBe((int) $userAConfig->id);
        });

        it('returns the global config row for any non-admin caller', function (): void {
            // Walks the is_global branch of applyVisibleScope().
            $auth = bootAuthLayer();
            $owner = bootAuth($auth, 'spc-get-config-owner@example.com', SPC_TEST_PASSWORD);
            bootAdmin($owner, $auth);
            $nonAdmin = bootAuth($auth, 'spc-get-config-other@example.com', SPC_TEST_PASSWORD);

            $service = makeSpeechConfigService();
            $global = $service->createConfiguration($owner, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => true,
                'settings' => [
                    'api_key' => 'sk-g',
                    'display_name' => 'Global',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: true);
            createUserPrincipalPublic($nonAdmin);

            $found = $service->getConfiguration((int) $global->id, $nonAdmin, isAdmin: false);
            expect($found)->not->toBeNull()
                ->and((int) $found->id)->toBe((int) $global->id);
        });

        it('hides configs the caller does not control (returns null)', function (): void {
            // applyVisibleScope() narrows the WHERE to caller-visible
            // principals, so foreign-principal rows never match.
            // Existence-hide, not 404.
            $auth = bootAuthLayer();
            $userA = bootAuth($auth, 'spc-get-config-a2@example.com', SPC_TEST_PASSWORD);
            $userB = bootAuth($auth, 'spc-get-config-b2@example.com', SPC_TEST_PASSWORD);

            $service = makeSpeechConfigService();
            $userBConfig = $service->createConfiguration($userB, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => false,
                'display_name' => 'B',
                'settings' => [
                    'api_key' => 'sk-b',
                    'display_name' => 'B',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: false);
            createUserPrincipalPublic($userA);

            $found = $service->getConfiguration((int) $userBConfig->id, $userA, isAdmin: false);
            expect($found)->toBeNull();
        });

        it('does not call applyVisibleScope() for admin callers', function (): void {
            // Admins bypass the visibility gate — getConfiguration() with
            // isAdmin=true returns the row regardless of principal.
            $auth = bootAuthLayer();
            $userA = bootAuth($auth, 'spc-get-config-a3@example.com', SPC_TEST_PASSWORD);
            $userB = bootAuth($auth, 'spc-get-config-b3@example.com', SPC_TEST_PASSWORD);
            bootAdmin($userA, $auth);

            $service = makeSpeechConfigService();
            $userBConfig = $service->createConfiguration($userB, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => false,
                'display_name' => 'B',
                'settings' => [
                    'api_key' => 'sk-b',
                    'display_name' => 'B',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: false);
            createUserPrincipalPublic($userA);
            createUserPrincipalPublic($userB);

            $found = $service->getConfiguration((int) $userBConfig->id, $userA, isAdmin: true);
            expect($found)->not->toBeNull()
                ->and((int) $found->id)->toBe((int) $userBConfig->id);
        });

        it('hides per-principal configs from a non-admin caller with no visible principals (empty-principals branch)', function (): void {
            // Exercises the `whereRaw(self::NO_MATCH)` branch that the
            // prior 4 tests never hit — global rows survive via the
            // OR'd `is_global = true`.
            $auth = bootAuthLayer();
            $admin = bootAuth($auth, 'spc-get-config-admin4@example.com', SPC_TEST_PASSWORD);
            bootAdmin($admin, $auth);
            $userB = bootAuth($auth, 'spc-get-config-b4@example.com', SPC_TEST_PASSWORD);
            createUserPrincipalPublic($userB);
            $isolated = bootAuth($auth, 'spc-get-config-isolated@example.com', SPC_TEST_PASSWORD);
            // Note: no createUserPrincipalPublic($isolated); we need
            // visiblePrincipalIds() to resolve to [] for this test to
            // exercise the empty-principals branch.

            $service = makeSpeechConfigService();
            $perPrincipal = $service->createConfiguration($userB, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => false,
                'display_name' => 'B',
                'settings' => [
                    'api_key' => 'sk-b',
                    'display_name' => 'B',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: false);
            $global = $service->createConfiguration($admin, [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => true,
                'settings' => [
                    'api_key' => 'sk-g',
                    'display_name' => 'Global',
                    'base_url' => 'https://api.openai.com/v1',
                    'model' => 'whisper-1',
                ],
            ], isAdmin: true);

            expect($service->getConfiguration((int) $perPrincipal->id, $isolated, isAdmin: false))
                ->toBeNull();

            $foundGlobal = $service->getConfiguration((int) $global->id, $isolated, isAdmin: false);
            expect($foundGlobal)->not->toBeNull()
                ->and((int) $foundGlobal->id)->toBe((int) $global->id);
        });
    });
});
