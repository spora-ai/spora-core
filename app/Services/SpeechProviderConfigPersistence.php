<?php

declare(strict_types=1);

namespace Spora\Services;

use Closure;
use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Http\Exceptions\SpeechProviderConfigException;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Models\PrincipalPreference;
use Spora\Models\SpeechProviderConfiguration;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Spora\Speech\SpeechToTextRegistry;

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
 *
 * Error model: schema/validation failures raise
 * {@see SpeechProviderConfigException::validation()} (422) and auth
 * failures (non-admin trying to write a global, or non-owner trying
 * to edit someone else's config) raise `forbidden()` (403). Lookups
 * that can't see the row at all raise `notFound()` (404). The
 * controller's `mapException()` helper maps these to the right wire
 * status without the controller having to re-derive the cause.
 */
final class SpeechProviderConfigPersistence
{
    private readonly SecurityManagerInterface $security;
    private readonly SpeechProviderConfigValidator $validator;
    /**
     * Lazy resolver into {@see SpeechToTextRegistry}. Mirror of
     * {@see SpeechToTextRegistry::$persistenceResolver} — the eager
     * graph is cyclic, so PHP-DI injects a Closure and defers
     * resolution to {@see configResource()} call time. Null when the
     * persistence is built without a registry (unit-test path) so
     * {@see configResource()} can still emit a response without the
     * provider's display name.
     *
     * @var (Closure(): ?SpeechToTextRegistry)|null
     */
    private readonly ?Closure $speechRegistryResolver;
    private readonly PrincipalResolver $principalResolver;

    public function __construct(
        SecurityManagerInterface $security,
        SpeechProviderConfigValidator $validator,
        ?Closure $speechRegistryResolver = null,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->speechRegistryResolver = $speechRegistryResolver;
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
     * @throws SpeechProviderConfigException 422 on schema/validation
     *         failure (unknown provider class, missing required
     *         setting, regex mismatch); 403 when a non-admin sets
     *         `is_global=true`.
     * @throws PrincipalNotAccessibleException when the caller cannot
     *         write under the requested principal.
     */
    public function createConfiguration(int $principalId, int $callerUserId, array $data, bool $isAdmin): SpeechProviderConfiguration
    {
        $validated = $this->validateNewConfigurationInputs($data, $isAdmin);
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
     *
     * @throws SpeechProviderConfigException 404 when the config is
     *         not visible to the caller; 403 when it is visible but
     *         the caller cannot edit it; 422 on schema/validation
     *         failure.
     */
    public function updateConfiguration(int $configId, int $callerUserId, array $data, bool $isAdmin): SpeechProviderConfiguration
    {
        $config = $this->loadEditableConfiguration($configId, $isAdmin, $callerUserId);
        if ($config === null) {
            // Visibility-not-found: caller cannot see the row at all
            // → 404. Editable-not-found (visible but not allowed) is
            // raised as 403 by loadEditableConfiguration.
            throw $this->findConfigurationForCaller($configId, $callerUserId, $isAdmin) === null
                ? SpeechProviderConfigException::notFound("Speech provider configuration {$configId} not found.")
                : SpeechProviderConfigException::forbidden("Not authorised to edit speech provider configuration {$configId}.");
        }

        $applied = $this->applyConfigurationUpdates($config, $data);
        if ($applied === null) {
            throw SpeechProviderConfigException::validation(
                'Invalid update payload for speech provider configuration.',
            );
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
     *
     * @throws SpeechProviderConfigException 404 when the row is
     *         invisible; 403 when visible but not owned by a non-admin.
     */
    public function deleteConfiguration(int $configId, int $callerUserId, bool $isAdmin): bool
    {
        $config = $this->findConfigurationForCaller($configId, $callerUserId, $isAdmin);
        if ($config === null) {
            throw SpeechProviderConfigException::notFound(
                "Speech provider configuration {$configId} not found.",
            );
        }
        if (!$isAdmin) {
            if ($config->is_global) {
                throw SpeechProviderConfigException::forbidden(
                    'Not authorised to delete a global speech provider configuration.',
                );
            }
            if (!$this->principalResolver->isPrincipalOwner($callerUserId, (int) $config->principal_id)) {
                throw SpeechProviderConfigException::forbidden(
                    "Not authorised to delete speech provider configuration {$configId}.",
                );
            }
        }

        $this->detachConfigurationReferencesStatic($configId);
        $config->delete();

        return true;
    }

    /**
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
     * The SPA filters list entries by `scope` (see
     * {@see \Spora\Speech\SpeechToTextRegistry::allProviders()} for the
     * matching provider-name lookup), so this serializer emits three
     * derived fields on top of the raw `is_global` + `principal_id`
     * pair:
     *   - `provider_name`         — class-level stable key
     *   - `provider_display_name` — operator-facing label
     *   - `scope`                 — 'global' | 'user' | 'group'
     *
     * The raw fields stay so the SPA doesn't need a second endpoint for
     * the admin-only branch.
     *
     * @return array<string, mixed>
     */
    public function configResource(SpeechProviderConfiguration $config): array
    {
        $settings = $this->decodeSettings($config->provider_class, $config->getRawOriginal('settings'));
        $masked = $this->maskForApi($config->provider_class, $settings);

        $providerName = null;
        $providerDisplayName = null;
        $registry = $this->resolveSpeechRegistry();
        if ($registry !== null) {
            foreach ($registry->allProviders() as $provider) {
                if ($provider::class === $config->provider_class) {
                    $providerName = $provider->getName();
                    $providerDisplayName = $provider->getDisplayName();
                    break;
                }
            }
        }

        $scope = 'global';
        if (!$config->is_global && $config->principal_id !== null) {
            $principal = Principal::find((int) $config->principal_id);
            $scope = ($principal !== null && $principal->type === Principal::TYPE_GROUP) ? 'group' : 'user';
        }

        return [
            'id' => (int) $config->id,
            'provider_class' => $config->provider_class,
            'provider_name' => $providerName,
            'provider_display_name' => $providerDisplayName,
            'display_name' => $config->display_name,
            'is_default' => (bool) $config->is_default,
            'is_global' => (bool) $config->is_global,
            'principal_id' => $config->principal_id !== null ? (int) $config->principal_id : null,
            'scope' => $scope,
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
     * Invoke the lazy {@see $speechRegistryResolver}; returns null when
     * no resolver was wired (unit-test / controller-less paths).
     */
    private function resolveSpeechRegistry(): ?SpeechToTextRegistry
    {
        if ($this->speechRegistryResolver === null) {
            return null;
        }
        $registry = ($this->speechRegistryResolver)();
        return $registry instanceof SpeechToTextRegistry ? $registry : null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function encodeSettingsForStorage(string $providerClass, array $settings): string
    {
        return $this->encodeSettingsString($providerClass, $settings);
    }

    /**
     * @throws SpeechProviderConfigException 422 on schema failure; 403
     *         when a non-admin sets `is_global=true`.
     *
     * @return array{provider_class: string, display_name: string, settings: array<string, mixed>, is_global: bool}
     */
    private function validateNewConfigurationInputs(array $data, bool $isAdmin): array
    {
        $providerClass = trim((string) ($data['provider_class'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $providerClass;
        }
        $rawSettings = $data['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $isGlobal = !empty($data['is_global']);

        if ($providerClass === '') {
            throw SpeechProviderConfigException::validation(
                "Field 'provider_class' is required and must be a non-empty string.",
            );
        }
        if (!$this->validator->isRegisteredProviderClass($providerClass)) {
            throw SpeechProviderConfigException::notFound(
                "Speech provider class '{$providerClass}' is not registered.",
            );
        }
        if ($isGlobal && !$isAdmin) {
            throw SpeechProviderConfigException::forbidden(
                'Only admins can create global speech provider configurations.',
            );
        }

        $this->validator->assertSettingsAgainstSchema($providerClass, $settings);

        return [
            'provider_class' => $providerClass,
            'display_name' => $displayName,
            'settings' => $settings,
            'is_global' => $isGlobal,
        ];
    }

    /**
     * Visibility-scoped lookup: returns the row only if the caller
     * can see it under the same rules as
     * {@see getConfiguration()}. Used by the update / delete paths so
     * "row doesn't exist for them" surfaces as 404 and "row exists but
     * they can't edit it" surfaces as 403.
     */
    private function findConfigurationForCaller(int $configId, int $callerUserId, bool $isAdmin): ?SpeechProviderConfiguration
    {
        $query = SpeechProviderConfiguration::where('id', $configId);
        if (!$isAdmin) {
            $principalIds = $this->principalResolver->visiblePrincipalIds($callerUserId);
            $query->where(static function ($q) use ($principalIds): void {
                if ($principalIds === []) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('principal_id', $principalIds);
                }
                $q->orWhere('is_global', true);
            });
        }
        return $query->first();
    }

    /**
     * @throws SpeechProviderConfigException 404 when not visible;
     *         403 when visible but not editable.
     */
    private function loadEditableConfiguration(int $configId, bool $isAdmin, int $callerUserId): ?SpeechProviderConfiguration
    {
        $config = $this->findConfigurationForCaller($configId, $callerUserId, $isAdmin);
        if ($config === null) {
            return null;
        }
        return $this->callerMayEdit($config, $isAdmin, $callerUserId) ? $config : null;
    }

    /**
     * Admins can edit any row. Non-admins are restricted to configs
     * they own (their own user-principal configs, or configs they
     * admin via a group); globals are off-limits. Extracted so the
     * loader stays under the S1142 3-return ceiling.
     */
    private function callerMayEdit(SpeechProviderConfiguration $config, bool $isAdmin, int $callerUserId): bool
    {
        if ($isAdmin) {
            return true;
        }
        if ($config->is_global) {
            return false;
        }
        return $this->principalResolver->isPrincipalOwner($callerUserId, (int) $config->principal_id);
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
            $incoming = $data['settings'];
            // The SPA masks password fields with `***` on GET; a PUT that
            // re-sends `***` means "leave the stored value alone". Strip
            // the sentinel so the merge keeps the existing key instead
            // of re-encrypting the literal three-character placeholder,
            // which would otherwise leave the provider unconfigured and
            // 503 the next transcribe call. Mirrors the LLM-side pattern
            // in {@see ToolConfigService::putGlobalSettings()}.
            foreach ($incoming as $key => $value) {
                if ($value === '***' && array_key_exists($key, $existing)) {
                    $incoming[$key] = $existing[$key];
                }
            }
            $merged = array_merge($existing, $incoming);
            $config->settings = $this->encodeSettingsString($config->provider_class, $merged);
        }

        return true;
    }
}
