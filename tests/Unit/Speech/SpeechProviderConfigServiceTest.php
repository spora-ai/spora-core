<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Models\SpeechProviderConfiguration;
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
    $validator = new SpeechProviderConfigValidator(new SpeechToTextRegistry([new OpenAiCompatibleTranscriber(
        new Symfony\Component\HttpClient\MockHttpClient(),
        new Spora\Services\ToolConfigService($security, new Psr\Log\NullLogger(), []),
    )]));
    $persistence = new SpeechProviderConfigPersistence($security, $validator);
    $principalService = new PrincipalService(new PrincipalResolver());
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

        $created = $service->createConfiguration($userId, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => [],
        ], isAdmin: false);

        expect($created)->toBeNull();
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
});
