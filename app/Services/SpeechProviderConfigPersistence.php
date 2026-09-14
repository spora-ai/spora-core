<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Models\Agent;
use Spora\Models\PrincipalPreference;
use Spora\Models\SpeechProviderConfiguration;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;

/**
 * Persistence layer for {@see SpeechProviderConfiguration}.
 *
 * Owns create / update / delete + settings encryption / decryption +
 * default-toggle bookkeeping + reference detach (mirrors
 * {@see LLMConfigPersistence}'s shape for the LLM side so the two
 * surfaces stay in lockstep).
 *
 * The schema is read from
 * {@see SpeechProviderConfigValidator::collectSettingsSchema()}
 * which walks `#[ToolSetting]` attributes on the provider class —
 * non-password fields are stored as plain JSON, password fields are
 * per-row encrypted via `SecurityManager`.
 *
 * Cascade on delete: any row with `agents.speech_driver_config_id`
 * pointing at the doomed config is nulled, and any
 * `principal_preferences.preferred_speech_config_id` pointing at it
 * is dropped. The agent-side FK is `ON DELETE SET NULL` so the row
 * detached pass is a belt-and-braces protection for callers that
 * bypass the persistence layer.
 */
final class SpeechProviderConfigPersistence
{
    private readonly SecurityManagerInterface $security;
    private readonly SpeechProviderConfigValidator $validator;
    private readonly PrincipalResolver $principalResolver;

    public function __construct(
        SecurityManagerInterface $security,
        SpeechProviderConfigValidator $validator,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    /**
     * Create a new config row. Global rows require admin;
     * principal-scoped rows must be under a principal the caller
     * controls (gate runs before the save so an out-of-scope principal
     * can't get a transient half-written row).
     *
     * @param array<string, mixed> $data
     *
     * @throws PrincipalNotAccessibleException when the caller cannot
     *         write under the requested principal.
     */
    public function createConfiguration(int $principalId, int $callerUserId, array $data, bool $isAdmin): ?SpeechProviderConfiguration
    {
        $validated = $this->validateNewConfigurationInputs($data, $isAdmin);
        if ($validated === null) {
            return null;
        }

        $isGlobal = $validated['is_global'];

        if (!$isGlobal && !in_array($principalId, $this->principalResolver->visiblePrincipalIds($callerUserId), true)) {
            throw new PrincipalNotAccessibleException("Caller {$callerUserId} cannot create a config under principal {$principalId}");
        }

        $config = new SpeechProviderConfiguration();
        $config->principal_id = $isGlobal ? null : $principalId;
        $config->is_global = $isGlobal;
        $config->provider_class = $validated['provider_class'];
        $config->display_name = $validated['display_name'];
        $config->settings = $this->encodeSettingsForStorage($validated['provider_class'], $validated['settings']);
        $config->is_default = !empty($data['is_default']);
        $config->save();

        return $config;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateConfiguration(int $configId, int $callerUserId, array $data, bool $isAdmin): ?SpeechProviderConfiguration
    {
        $config = $this->loadEditableConfiguration($configId, $isAdmin, $callerUserId);
        if ($config === null) {
            return null;
        }

        $applied = $this->applyConfigurationUpdates($config, $data);
        if ($applied === null) {
            return null;
        }

        $config->save();

        return $config;
    }

    /**
     * Delete a config. Detaches every FK reference first so we never
     * silently leave `agents.speech_driver_config_id` or
     * `principal_preferences.preferred_speech_config_id` pointing
     * at the doomed row.
     *
     * - Admin: deletes any config (global or principal-scoped).
     * - Non-admin: refuses on globals; principal-scoped rows require
     *   the caller to own the principal.
     */
    public function deleteConfiguration(int $configId, int $callerUserId, bool $isAdmin): bool
    {
        $config = SpeechProviderConfiguration::find($configId);
        if ($config === null) {
            return false;
        }

        if ($isAdmin) {
            $this->detachConfigurationReferencesStatic($configId);
            $config->delete();
            return true;
        }

        return $this->deleteConfigurationForNonAdmin($config, $callerUserId);
    }

    private function deleteConfigurationForNonAdmin(SpeechProviderConfiguration $config, int $callerUserId): bool
    {
        if ($config->is_global) {
            return false;
        }
        if (!$this->principalResolver->isPrincipalOwner($callerUserId, (int) $config->principal_id)) {
            return false;
        }

        $this->detachConfigurationReferencesStatic((int) $config->id);
        $config->delete();

        return true;
    }

    /**
     * Encode settings for storage: password fields encrypted
     * per-field, everything else plain JSON.
     *
     * @param array<string, mixed> $settings
     */
    public function encodeSettings(string $providerClass, array $settings): array
    {
        $allowed = array_flip(array_column(
            $this->validator->collectSettingsSchema($providerClass),
            'key',
        ));
        $pruned = array_intersect_key($settings, $allowed);

        $passwordKeys = $this->validator->passwordKeysFor($providerClass);
        $encoded = [];
        foreach ($pruned as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== null && $value !== '') {
                $encrypted = $this->security->encrypt((string) $value);
                $encoded[$key] = $encrypted->toStorageString();
            } else {
                $encoded[$key] = $value;
            }
        }

        return $encoded;
    }

    /**
     * Encode and serialise to a JSON string suitable for
     * `settings` column write.
     *
     * @param array<string, mixed> $settings
     */
    public function encodeSettingsString(string $providerClass, array $settings): string
    {
        return json_encode($this->encodeSettings($providerClass, $settings), JSON_THROW_ON_ERROR);
    }

    /**
     * Decode the encrypted JSON string back to a plain array.
     *
     * @return array<string, mixed>
     */
    public function decodeSettings(string $providerClass, ?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if ($this->security->looksEncrypted($raw)) {
            $json = $this->security->decrypt(new EncryptedValue($raw));
            return is_array($decoded = json_decode($json, true)) ? $decoded : [];
        }

        $data = json_decode($raw, true) ?: [];
        $passwordKeys = $this->validator->passwordKeysFor($providerClass);

        foreach ($passwordKeys as $key) {
            if (isset($data[$key]) && $data[$key] !== '' && is_string($data[$key])) {
                try {
                    $data[$key] = $this->security->decrypt(new EncryptedValue($data[$key]));
                } catch (DecryptionFailedException) {
                    $data[$key] = null;
                }
            }
        }

        return $data;
    }

    /**
     * Build the wire shape (masked settings + timestamps + flags) for
     * a single config row. Used by the controller to render the
     * CRUD responses.
     *
     * @return array<string, mixed>
     */
    public function configResource(SpeechProviderConfiguration $config): array
    {
        $settings = $this->decodeSettings($config->provider_class, $config->getRawOriginal('settings'));
        $masked = $this->maskForApi($config->provider_class, $settings);

        return [
            'id' => (int) $config->id,
            'provider_class' => $config->provider_class,
            'display_name' => $config->display_name,
            'is_default' => (bool) $config->is_default,
            'is_global' => (bool) $config->is_global,
            'principal_id' => $config->principal_id !== null ? (int) $config->principal_id : null,
            'settings' => $masked,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function maskForApi(string $providerClass, array $settings): array
    {
        $passwordKeys = $this->validator->passwordKeysFor($providerClass);
        $masked = [];
        foreach ($settings as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== '' && $value !== null) {
                $masked[$key] = '***';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    /**
     * Static detach helper, mirrors
     * {@see LLMConfigPersistence::detachConfigurationReferencesStatic()}.
     * Callers that don't (or shouldn't) instantiate the persistence
     * layer just to null dangling FKs before a delete use this entry
     * point.
     */
    public static function detachConfigurationReferencesStatic(int $configId): void
    {
        Agent::where('speech_driver_config_id', $configId)->update(['speech_driver_config_id' => null]);
        PrincipalPreference::where('preferred_speech_config_id', $configId)->update(['preferred_speech_config_id' => null]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function encodeSettingsForStorage(string $providerClass, array $settings): string
    {
        return $this->encodeSettingsString($providerClass, $settings);
    }

    /**
     * @return array{provider_class: string, display_name: string, settings: array<string, mixed>, is_global: bool}|null
     */
    private function validateNewConfigurationInputs(array $data, bool $isAdmin): ?array
    {
        $providerClass = trim((string) ($data['provider_class'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $providerClass;
        }
        $rawSettings = $data['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $isGlobal = !empty($data['is_global']);

        $invalid = $providerClass === ''
            || !$this->validator->isRegisteredProviderClass($providerClass)
            || ($isGlobal && !$isAdmin);

        if ($invalid) {
            return null;
        }

        try {
            $this->validator->assertSettingsAgainstSchema($providerClass, $settings);
        } catch (\Spora\Http\Exceptions\SpeechProviderConfigException) {
            return null;
        }

        return [
            'provider_class' => $providerClass,
            'display_name' => $displayName,
            'settings' => $settings,
            'is_global' => $isGlobal,
        ];
    }

    private function loadEditableConfiguration(int $configId, bool $isAdmin, int $callerUserId): ?SpeechProviderConfiguration
    {
        $config = SpeechProviderConfiguration::find($configId);
        if ($config === null) {
            return null;
        }

        // Admins can edit anything.
        if ($isAdmin) {
            return $config;
        }

        // Non-admins cannot edit global configs.
        if ($config->is_global) {
            return null;
        }

        // Non-admins can only edit configs they own (their own
        // user-principal configs, or configs they admin via a group).
        if (!$this->principalResolver->isPrincipalOwner($callerUserId, (int) $config->principal_id)) {
            return null;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyConfigurationUpdates(SpeechProviderConfiguration $config, array $data): ?bool
    {
        if (array_key_exists('display_name', $data)) {
            $name = trim((string) $data['display_name']);
            if ($name === '') {
                return null;
            }
            $config->display_name = $name;
        }

        if (isset($data['settings']) && is_array($data['settings']) && !array_is_list($data['settings'])) {
            $existing = $this->decodeSettings($config->provider_class, $config->getRawOriginal('settings') ?? '');
            $merged = array_merge($existing, $data['settings']);
            $config->settings = $this->encodeSettingsString($config->provider_class, $merged);
        }

        return true;
    }
}
