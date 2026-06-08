<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
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
        'max_tokens' => 2048,
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
