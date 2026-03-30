<?php

declare(strict_types=1);

use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManager;
use Spora\Core\ValueObjects\EncryptedValue;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeValidKey(): string
{
    return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function makeSecurityManager(?string $key = null): SecurityManager
{
    return new SecurityManager($key ?? makeValidKey());
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('encrypt and decrypt roundtrip returns original plaintext', function (): void {
    $sm        = makeSecurityManager();
    $plaintext = 'super secret value';

    $encrypted = $sm->encrypt($plaintext);

    expect($encrypted)->toBeInstanceOf(EncryptedValue::class);
    expect($sm->decrypt($encrypted))->toBe($plaintext);
});

test('two encryptions of the same value produce different ciphertexts (nonce randomness)', function (): void {
    $sm        = makeSecurityManager();
    $plaintext = 'same input';

    $enc1 = $sm->encrypt($plaintext);
    $enc2 = $sm->encrypt($plaintext);

    expect($enc1->toStorageString())->not()->toBe($enc2->toStorageString());
});

test('decrypting with wrong key throws DecryptionFailedException', function (): void {
    $sm1 = makeSecurityManager(makeValidKey());
    $sm2 = makeSecurityManager(makeValidKey());

    $encrypted = $sm1->encrypt('secret');

    expect(fn() => $sm2->decrypt($encrypted))->toThrow(DecryptionFailedException::class);
});

test('constructing with a key shorter than 32 bytes throws RuntimeException', function (): void {
    expect(fn() => new SecurityManager('tooshort'))->toThrow(RuntimeException::class);
});

test('constructing with a key from a file path works correctly', function (): void {
    $key      = makeValidKey();
    $tmpFile  = tempnam(sys_get_temp_dir(), 'spora_key_');

    file_put_contents($tmpFile, $key);

    try {
        $sm        = new SecurityManager($tmpFile);
        $plaintext = 'file-based key test';

        $encrypted = $sm->encrypt($plaintext);
        expect($sm->decrypt($encrypted))->toBe($plaintext);
    } finally {
        unlink($tmpFile);
    }
});

test('looksEncrypted returns true for valid ciphertext blob', function (): void {
    $sm        = makeSecurityManager();
    $encrypted = $sm->encrypt('hello');

    expect($sm->looksEncrypted($encrypted->toStorageString()))->toBeTrue();
});

test('looksEncrypted returns false for plaintext string', function (): void {
    $sm = makeSecurityManager();

    expect($sm->looksEncrypted('plaintext_value'))->toBeFalse();
});

test('EncryptedValue cannot be cast to string', function (): void {
    $sm  = makeSecurityManager();
    $enc = $sm->encrypt('test');

    expect(fn() => (string) $enc)->toThrow(LogicException::class);
});
