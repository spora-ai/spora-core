<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;

/**
 * Encodes, encrypts, decrypts and decodes tool settings for storage.
 *
 * The cryptographer owns the per-field encryption policy: only fields
 * declared as `type: password` are encrypted; everything else is stored
 * as plain JSON. The password key list is resolved at call time via a
 * callable injected by the facade (the schema inspector already knows
 * which keys are password fields for any given tool class).
 */
final class ToolConfigCryptographer
{
    /** @var callable(string $toolClass): list<string> */
    private $passwordKeyResolver;

    /**
     * @param callable(string $toolClass): list<string> $passwordKeyResolver
     *        Returns the list of setting keys for the given tool class
     *        that must be encrypted at rest (those declared as
     *        `#[ToolSetting(type: 'password')]`).
     */
    public function __construct(
        private readonly SecurityManagerInterface $security,
        callable $passwordKeyResolver,
    ) {
        $this->passwordKeyResolver = $passwordKeyResolver;
    }

    /**
     * Decode raw JSON from DB, decrypting password fields.
     * Returns [] for null/empty input.
     * DecryptionFailedException is caught per-field: that field becomes null.
     *
     * @return array<string, mixed>
     */
    public function decodeSettings(string $toolClass, ?string $rawJson): array
    {
        if ($rawJson === null || $rawJson === '') {
            return [];
        }

        if ($this->isEncryptedBlob($rawJson)) {
            return $this->decryptSettings($rawJson);
        }

        return $this->legacyDecodeSettings($toolClass, $rawJson);
    }

    /**
     * Encrypt a settings array to a storage string.
     * Only password fields are encrypted per-field; all other fields are stored as plain JSON.
     *
     * @param array<string, mixed> $settings
     */
    public function encryptSettings(string $toolClass, array $settings): string
    {
        $passwordKeys = ($this->passwordKeyResolver)($toolClass);
        $result = [];
        foreach ($settings as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== null && $value !== '') {
                $result[$key] = $this->security->encrypt((string) $value)->toStorageString();
            } else {
                $result[$key] = $value;
            }
        }
        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * Decrypt a storage string back to a plain settings array.
     *
     * @return array<string, mixed>
     */
    public function decryptSettings(string $storageString): array
    {
        $encrypted = new EncryptedValue($storageString);
        $json = $this->security->decrypt($encrypted);
        return json_decode($json, true) ?? [];
    }

    /**
     * Filter settings: remove fields set to the "***" sentinel (preserve-existing marker).
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function filterSettings(string $toolClass, array $settings): array
    {
        $passwordKeys = ($this->passwordKeyResolver)($toolClass);
        return array_filter(
            $settings,
            fn($v, $k) => !($v === '***' && in_array($k, $passwordKeys, true)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Detect whether a raw DB value is an encrypted blob (new format) or plain JSON (legacy).
     */
    private function isEncryptedBlob(string $raw): bool
    {
        return $this->security->looksEncrypted($raw);
    }

    /**
     * Decode legacy plain-JSON stored settings (per-field password decryption).
     *
     * @return array<string, mixed>
     */
    private function legacyDecodeSettings(string $toolClass, string $rawJson): array
    {
        $data = json_decode($rawJson, true);
        if (!is_array($data)) {
            return [];
        }

        $passwordKeys = ($this->passwordKeyResolver)($toolClass);
        $result       = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== null && $value !== '') {
                try {
                    $result[$key] = $this->security->decrypt(new EncryptedValue((string) $value));
                } catch (DecryptionFailedException) {
                    error_log("ToolConfigCryptographer: decryption failed for field {$key} of {$toolClass}");
                    $result[$key] = null;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
