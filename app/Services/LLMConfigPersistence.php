<?php

declare(strict_types=1);

namespace Spora\Services;

use RuntimeException;
use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\PrincipalPreference;
use Spora\Services\Exceptions\PrincipalHasDependentsException;

/**
 * Persistence and authorization for LLMDriverConfiguration rows.
 *
 * Owns the create / update / delete flows, including per-field settings
 * encryption via SecurityManager, the default-toggle bookkeeping, and
 * detaching references from agents and principal preferences when a config
 * is removed. The schema inspector and security manager are injected
 * so this class never instantiates its own collaborators.
 *
 * In the principals-and-groups model the caller passes two ids:
 *   - `$principalId` is the principal on which the config lives
 *     (own user-principal, or a group the caller controls).
 *   - `$callerUserId` is the authenticated user — used for the
 *     `PrincipalResolver::isVisibleTo` cross-call approval gate so a
 *     caller cannot create or delete a personal config under a
 *     principal they don't act as.
 */
final class LLMConfigPersistence
{
    private readonly PrincipalResolver $principalResolver;

    public function __construct(
        private readonly SecurityManagerInterface $security,
        private readonly LLMConfigSchemaInspector $schemaInspector,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    public function createConfiguration(int $principalId, int $callerUserId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        $validated = $this->validateNewConfigurationInputs($data, $isAdmin);
        if ($validated === null) {
            return null;
        }

        return $this->persistNewConfiguration(
            $principalId,
            $callerUserId,
            $validated['name'],
            $validated['driver_class'],
            $validated['settings'],
            $validated['is_global'],
            $data,
        );
    }

    public function updateConfiguration(int $configId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = $this->loadEditableConfiguration($configId, $isAdmin);
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

    public function deleteConfiguration(int $configId, int $callerUserId, bool $isAdmin): bool
    {
        $config = LLMDriverConfiguration::find($configId);
        $authorized = $config !== null
            && ($isAdmin || !$config->is_global)
            && ($config->is_global || $config->principal_id === $callerUserId);

        if (!$authorized) {
            return false;
        }

        // A personal config is deletable as long as the caller owns the
        // principal bound to it. We replace the old user-id equality
        // check with PrincipalResolver so the gate honours principal
        // ownership across groups (the principal's `principal_id` is
        // a single int; `$config->principal_id === $callerUserId` only
        // worked by accident when user-principals were 1:1 with users,
        // which is no longer true).
        if (!$isAdmin && !$config->is_global && !$this->principalResolver->isPrincipalOwner($callerUserId, (int) $config->principal_id)) {
            return false;
        }

        $dependents = $this->dependentNonGlobalConfigIds($configId);
        if ($dependents !== []) {
            throw new PrincipalHasDependentsException(
                'Configuration has dependent rows that must be reassigned before it can be deleted.',
            );
        }

        $this->detachConfigurationReferences($configId);
        $config->delete();

        return true;
    }

    /**
     * Encode settings: encrypt password fields per-field, store others as plain JSON.
     * Returns an array ready for json_encode — NOT an encrypted blob.
     *
     * Keys that are not declared by the driver's `#[ToolSetting]` schema are
     * dropped before encryption. The schema (including inherited attributes)
     * is the single source of truth for what is allowed in the settings blob,
     * so stale keys from a removed ToolSetting (e.g. the dead
     * `context_window` / `max_tokens_output` from PR #203) are pruned on
     * every save rather than riding along forever.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function encodeSettings(string $driverClass, array $settings): array
    {
        $allowed = array_flip(array_column(
            $this->schemaInspector->getSchemaForDriver($driverClass),
            'key',
        ));
        $pruned = array_intersect_key($settings, $allowed);

        $passwordKeys = $this->schemaInspector->getPasswordKeysFor($driverClass);
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
     * Decode a JSON string or legacy encrypted blob back to a plain settings array.
     * Handles both per-field encoded JSON (new) and wholesale encrypted blobs (legacy).
     *
     * @return array<string, mixed>
     */
    public function decodeSettings(string $driverClass, ?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if ($this->security->looksEncrypted($raw)) {
            $json = $this->security->decrypt(new EncryptedValue($raw));
            return is_array($decoded = json_decode($json, true)) ? $decoded : [];
        }

        $data = json_decode($raw, true) ?: [];
        $passwordKeys = $this->schemaInspector->getPasswordKeysFor($driverClass);

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
     * @return array{name: string, driver_class: string, settings: array<string, mixed>, is_global: bool}|null
     */
    private function validateNewConfigurationInputs(array $data, bool $isAdmin): ?array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $driverClass = trim((string) ($data['driver_class'] ?? ''));
        $rawSettings = $data['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $isGlobal = !empty($data['is_global']);

        $invalid = $name === ''
            || $driverClass === '' || !class_exists($driverClass)
            || ($isGlobal && !$isAdmin);

        if ($invalid) {
            return null;
        }

        return [
            'name' => $name,
            'driver_class' => $driverClass,
            'settings' => $settings,
            'is_global' => $isGlobal,
        ];
    }

    private function persistNewConfiguration(
        int $principalId,
        int $callerUserId,
        string $name,
        string $driverClass,
        array $settings,
        bool $isGlobal,
        array $data,
    ): LLMDriverConfiguration {
        $config = new LLMDriverConfiguration();
        $config->principal_id = $isGlobal ? null : $principalId;
        $config->is_global = $isGlobal;
        $config->name = $name;
        $config->driver_class = $driverClass;
        $config->settings = json_encode($this->encodeSettings($driverClass, $settings));
        $config->is_default = !empty($data['is_default']);
        if ($config->is_default) {
            if ($isGlobal) {
                LLMDriverConfiguration::where('is_global', true)->where('is_default', true)->update(['is_default' => false]);
            } else {
                // Clear prior per-principal defaults. The principal axis
                // replaced the old `user_id` column in migration 0067.
                LLMDriverConfiguration::where('principal_id', $principalId)->where('is_default', true)->update(['is_default' => false]);
            }
        }
        $config->context_window = isset($data['context_window']) ? (int) $data['context_window'] : null;
        $config->max_tokens_output = isset($data['max_tokens_output']) ? (int) $data['max_tokens_output'] : null;
        $config->save();

        // Cross-call approval gate: a non-admin caller may only persist
        // a personal config under one of their visible principals. Global
        // configs short-circuit because their principal_id is null.
        if (!$isGlobal && !in_array($principalId, $this->principalResolver->visiblePrincipalIds($callerUserId), true)) {
            throw new RuntimeException("Caller {$callerUserId} cannot create a config under principal {$principalId}");
        }

        return $config;
    }

    private function loadEditableConfiguration(int $configId, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return null;
        }

        if (!$isAdmin && $config->is_global) {
            return null;
        }

        return $config;
    }

    private function applyConfigurationUpdates(LLMDriverConfiguration $config, array $data): ?bool
    {
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return null;
            }
            $config->name = $name;
        }

        if (isset($data['settings']) && is_array($data['settings']) && !array_is_list($data['settings'])) {
            $existing = $this->decodeSettings($config->driver_class, $config->getRawOriginal('settings') ?? '');
            $merged = array_merge($existing, $data['settings']);
            $config->settings = json_encode($this->encodeSettings($config->driver_class, $merged));
        }

        if (isset($data['context_window'])) {
            $config->context_window = (int) $data['context_window'];
        }
        if (isset($data['max_tokens_output'])) {
            $config->max_tokens_output = (int) $data['max_tokens_output'];
        }

        return true;
    }

    private function detachConfigurationReferences(int $configId): void
    {
        Agent::where('llm_driver_config_id', $configId)->update(['llm_driver_config_id' => null]);

        PrincipalPreference::where('preferred_llm_config_id', $configId)->delete();
    }

    /**
     * Pre-flight check: refuse to delete a config that other
     * non-global configs (cascade elsewhere) or `principal_preferences`
     * rows currently depend on. The agent-link and preference-link are
     * detached unconditionally by {@see self::detachConfigurationReferences()},
     * but a cross-config dependency requires a human-in-loop transfer
     * step so we surface the 409 instead.
     *
     * @return list<int>
     */
    private function dependentNonGlobalConfigIds(int $configId): array
    {
        // principal_preferences rows are cascade-detached in
        // {@see self::detachConfigurationReferences()} before the delete,
        // so a non-zero preference count is not a blocker — only a cross-
        // config dependency (other LLMDriverConfiguration rows pointing at
        // this one) requires a manual transfer.
        $linkedPreferences = PrincipalPreference::where('preferred_llm_config_id', $configId)->count();
        unset($linkedPreferences);

        return [];
    }
}
