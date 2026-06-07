<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Services\ToolConfigCryptographer;
use Spora\Services\ToolConfigSchemaInspector;
use Tests\Fixtures\TestTool;

/**
 * Standalone helper: build a real cryptographer backed by a freshly
 * generated key and a real schema inspector.
 */
function makeCryptographer(): ToolConfigCryptographer
{
    $security  = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $inspector = new ToolConfigSchemaInspector();

    return new ToolConfigCryptographer($security, $inspector->getPasswordKeys(...));
}

test('encryptSettings + decryptSettings round-trip a settings array', function (): void {
    $crypto = makeCryptographer();

    $encrypted = $crypto->encryptSettings(TestTool::class, [
        'api_key'     => 'top-secret',
        'max_results' => '25',
    ]);

    // Round-trip
    $decoded = $crypto->decodeSettings(TestTool::class, $encrypted);
    expect($decoded['api_key'])->toBe('top-secret');
    expect($decoded['max_results'])->toBe('25');
});

test('encryptSettings writes a single encrypted blob (new format)', function (): void {
    $crypto = makeCryptographer();

    $encrypted = $crypto->encryptSettings(TestTool::class, [
        'api_key'     => 'top-secret',
        'max_results' => '25',
    ]);

    // decodeSettings should hit the decryptSettings branch (blob is encrypted)
    // and return the same data after the round trip.
    $decoded = $crypto->decodeSettings(TestTool::class, $encrypted);
    expect($decoded)->toBe(['api_key' => 'top-secret', 'max_results' => '25']);
});

test('decodeSettings returns [] for null and empty input', function (): void {
    $crypto = makeCryptographer();

    expect($crypto->decodeSettings(TestTool::class, null))->toBe([]);
    expect($crypto->decodeSettings(TestTool::class, ''))->toBe([]);
});

test('decodeSettings of legacy plain-JSON decrypts password fields per-field', function (): void {
    $security  = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $inspector = new ToolConfigSchemaInspector();

    // Legacy format: hand-build a JSON blob where the password field is
    // already-encrypted (its own storage string).
    $encryptedApiKey = $security->encrypt('legacy-secret')->toStorageString();
    $legacyJson = json_encode([
        'api_key'     => $encryptedApiKey,
        'max_results' => '7',
    ], JSON_THROW_ON_ERROR);

    $crypto = new ToolConfigCryptographer($security, $inspector->getPasswordKeys(...));
    $decoded = $crypto->decodeSettings(TestTool::class, $legacyJson);

    expect($decoded['api_key'])->toBe('legacy-secret');
    expect($decoded['max_results'])->toBe('7');
});

test('filterSettings drops *** sentinels only for password fields', function (): void {
    $crypto = makeCryptographer();

    $filtered = $crypto->filterSettings(TestTool::class, [
        'api_key'     => '***',
        'max_results' => '***',  // not a password — must be preserved
        'custom_field' => 'kept',
    ]);

    expect($filtered)->not->toHaveKey('api_key');
    expect($filtered['max_results'])->toBe('***');
    expect($filtered['custom_field'])->toBe('kept');
});
