<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigPersistence;
use Spora\Services\LLMConfigSchemaInspector;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();
    Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
});

afterEach(function (): void {
    if (Illuminate\Database\Capsule\Manager::connection()->transactionLevel() > 0) {
        Illuminate\Database\Capsule\Manager::connection()->rollBack();
    }
    Spora\Core\Database::resetBootState();
});

function makePersistence(): LLMConfigPersistence
{
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $inspector = new LLMConfigSchemaInspector([
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);

    return new LLMConfigPersistence($security, $inspector);
}

test('encodeSettings encrypts only password-typed fields and leaves the rest plain', function (): void {
    $persistence = makePersistence();

    $encoded = $persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-test-key',
        'base_url' => 'https://api.example.com',
        'model' => 'gpt-4o',
    ]);

    // api_key should be encrypted (different from the input)
    expect($encoded['api_key'])->toBeString()
        ->and($encoded['api_key'])->not->toBe('sk-test-key');
    // Non-password keys stay plain
    expect($encoded['base_url'])->toBe('https://api.example.com')
        ->and($encoded['model'])->toBe('gpt-4o');
});

test('encodeSettings does not encrypt empty or null password values', function (): void {
    $persistence = makePersistence();

    $encoded = $persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => '',
        'model' => 'gpt-4o',
    ]);

    expect($encoded['api_key'])->toBe('')
        ->and($encoded['model'])->toBe('gpt-4o');
});

test('encodeSettings + decodeSettings is a lossless round-trip', function (): void {
    $persistence = makePersistence();

    $original = [
        'api_key' => 'sk-round-trip',
        'base_url' => 'https://example.com/v1',
        'model' => 'gpt-4o-mini',
        'timeout' => '120',
    ];

    $encoded = $persistence->encodeSettings(OpenAICompatibleDriver::class, $original);
    $json = json_encode($encoded);
    $decoded = $persistence->decodeSettings(OpenAICompatibleDriver::class, $json);

    expect($decoded)->toEqual($original);
});

test('decodeSettings returns an empty array for null and empty input', function (): void {
    $persistence = makePersistence();

    expect($persistence->decodeSettings(OpenAICompatibleDriver::class, null))->toBe([]);
    expect($persistence->decodeSettings(OpenAICompatibleDriver::class, ''))->toBe([]);
});

test('createConfiguration rejects empty name and returns null', function (): void {
    $persistence = makePersistence();

    $result = $persistence->createConfiguration(1, [
        'name' => '   ',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => [],
    ], false);

    expect($result)->toBeNull();
});

test('createConfiguration rejects non-admin trying to create a global config', function (): void {
    $persistence = makePersistence();

    $result = $persistence->createConfiguration(1, [
        'name' => 'Global',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk'],
        'is_global' => true,
    ], false);

    expect($result)->toBeNull();
});

test('createConfiguration rejects unknown driver class', function (): void {
    $persistence = makePersistence();

    $result = $persistence->createConfiguration(1, [
        'name' => 'Bogus',
        'driver_class' => 'Spora\\Drivers\\MissingDriver',
        'settings' => [],
    ], false);

    expect($result)->toBeNull();
});

test('createConfiguration persists a new config and clears existing defaults', function (): void {
    $persistence = makePersistence();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'persistence-defaults@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $first = $persistence->createConfiguration($userId, [
        'name' => 'First',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-1', 'model' => 'gpt-4o'],
        'is_default' => true,
    ], false);

    expect($first)->not->toBeNull()
        ->and($first->is_default)->toBeTrue()
        ->and($first->user_id)->toBe($userId);

    $second = $persistence->createConfiguration($userId, [
        'name' => 'Second',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-2', 'model' => 'gpt-4o-mini'],
        'is_default' => true,
    ], false);

    expect($second)->not->toBeNull()
        ->and($second->is_default)->toBeTrue();

    $first->refresh();
    expect($first->is_default)->toBeFalse();
});

test('encodeSettings drops keys not declared by the driver schema (regression: stale max_tokens_output / context_window from pre-#203 ToolSettings)', function (): void {
    $persistence = makePersistence();

    $encoded = $persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-test',
        'model' => 'gpt-4o',
        'temperature' => '0.7',
        // Stale keys that used to come from #[ToolSetting] before PR #203:
        'max_tokens_output' => '4096',
        'context_window' => '300000',
    ]);

    expect($encoded)->toHaveKeys(['api_key', 'model', 'temperature'])
        ->and($encoded)->not->toHaveKey('max_tokens_output')
        ->and($encoded)->not->toHaveKey('context_window');
});

test('encodeSettings preserves inherited ToolSetting attributes (e.g. supports_image_input from AbstractCompatibleDriver)', function (): void {
    $persistence = makePersistence();

    $encoded = $persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'supports_image_input' => 'true',
        'api_key' => 'sk-test',
    ]);

    expect($encoded)->toHaveKey('supports_image_input')
        ->and($encoded)->toHaveKey('api_key');
});

test('updateConfiguration prunes stale keys from the settings blob on save', function (): void {
    $persistence = makePersistence();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'prune@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Create with stale keys already in the blob (simulates a pre-#203 row)
    $created = $persistence->createConfiguration($userId, [
        'name' => 'Prune Target',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => [
            'api_key' => 'sk-stale',
            'model' => 'gpt-4o',
            'temperature' => '0.7',
            'max_tokens_output' => '4096',
            'context_window' => '300000',
        ],
    ], false);
    expect($created)->not->toBeNull();

    // updateConfiguration routes through encodeSettings → prune on write
    $updated = $persistence->updateConfiguration($created->id, [
        'settings' => ['api_key' => 'sk-stale', 'model' => 'gpt-4o-mini'],
    ], false);
    expect($updated)->not->toBeNull();

    $decoded = $persistence->decodeSettings(OpenAICompatibleDriver::class, $updated->settings);
    expect($decoded)->toHaveKey('api_key')
        ->and($decoded)->toHaveKey('model')
        ->and($decoded)->not->toHaveKey('max_tokens_output')
        ->and($decoded)->not->toHaveKey('context_window');

    LLMDriverConfiguration::where('id', $created->id)->delete();
});
