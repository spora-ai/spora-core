<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Core\SecurityManagerInterface;
use Spora\Models\Agent;
use Spora\Models\AgentToolOverride;
use Spora\Models\ToolConfiguration;
use Spora\Models\ToolUserSetting;

/**
 * The ONLY class permitted to read or write tool_configurations.settings
 * and agent_tool_overrides.settings columns.
 *
 * The Eloquent models have a guard accessor that throws LogicException on direct
 * `settings` access — all reads/writes must funnel through this service.
 *
 * This class is a thin facade. The schema/crypto/name responsibilities are
 * delegated to ToolConfigSchemaInspector, ToolConfigCryptographer and
 * ToolConfigNameResolver; the CRUD + effective-resolution orchestration
 * lives here.
 *
 * Not declared `final` to preserve Mockery::mock(ToolConfigService::class) in
 * existing tool tests (Mockery cannot mock final classes). The split itself
 * resolves the php:S1448 (too many methods) violation that motivated the
 * refactor. If a future change makes the class `final`, the consumer-side
 * type hints should be switched to ToolConfigServiceInterface.
 */
class ToolConfigService implements ToolConfigServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private readonly ToolConfigCryptographer $crypto;

    private readonly ToolConfigNameResolver $nameResolver;

    private readonly ToolConfigSchemaInspector $schema;

    /**
     * @param list<string> $toolClasses
     */
    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $toolClasses = [],
    ) {
        $this->schema = new ToolConfigSchemaInspector();
        $this->crypto = new ToolConfigCryptographer($security, $this->schema->getPasswordKeys(...));
        $this->nameResolver = new ToolConfigNameResolver($logger, $toolClasses);
    }

    /**
     * Load global settings for a tool class, decrypting password fields.
     *
     * @return array<string, mixed>
     */
    public function getGlobalSettings(string $toolClass): array
    {
        $model = ToolConfiguration::where('tool_class', $toolClass)->first();

        if ($model === null) {
            return [];
        }

        return $this->crypto->decodeSettings($toolClass, $model->getRawOriginal('settings'));
    }

    /**
     * Persist global settings for a tool class.
     * Settings are wholesale-encrypted; omitted keys are merged from existing stored values.
     */
    public function putGlobalSettings(string $toolClass, array $settings): void
    {
        $toolName = $this->nameResolver->getToolName($toolClass);

        // Merge with existing stored settings so omitted fields are preserved.
        // Only fields present in $settings are overwritten; everything else carries over.
        $existing = $this->getGlobalSettings($toolClass);

        // Replace '***' sentinel (masked password from client) with actual existing values.
        foreach ($settings as $key => $value) {
            if ($value === '***' && array_key_exists($key, $existing)) {
                $settings[$key] = $existing[$key];
            }
        }

        $merged   = array_merge($existing, $settings);
        // Filter out any remaining '***' sentinels before saving (only for password fields)
        $merged   = $this->crypto->filterSettings($toolClass, $merged);

        $encrypted = $this->crypto->encryptSettings($toolClass, $merged);

        $existingRecord = ToolConfiguration::where('tool_class', $toolClass)->first();

        if ($existingRecord !== null) {
            Capsule::table('tool_configurations')
                ->where('tool_class', $toolClass)
                ->update([
                    'tool_name'  => $toolName,
                    'settings'   => $encrypted,
                    'updated_at' => date(self::DATETIME_FORMAT),
                ]);
        } else {
            Capsule::table('tool_configurations')->insert([
                'tool_class'  => $toolClass,
                'tool_name'   => $toolName,
                'settings'    => $encrypted,
                'created_at'  => date(self::DATETIME_FORMAT),
                'updated_at'  => date(self::DATETIME_FORMAT),
            ]);
        }
    }

    /**
     * Load user-specific settings for a tool class, decrypting password fields.
     *
     * @return array<string, mixed>
     */
    public function getUserSettings(string $toolClass, int $userId): array
    {
        $model = ToolUserSetting::where('user_id', $userId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($model === null) {
            return [];
        }

        return $this->crypto->decodeSettings($toolClass, $model->getRawOriginal('settings'));
    }

    /**
     * Persist user-specific settings for a tool class.
     * Password fields are encrypted; other fields stored as plain strings.
     *
     * @return array<string, mixed> Decrypted settings (for immediate use)
     */
    public function putUserSettings(string $toolClass, int $userId, array $settings): array
    {
        // Merge with existing stored settings so omitted fields are preserved.
        $existingSettings = $this->getUserSettings($toolClass, $userId);

        // Replace '***' sentinel (masked password from client) with actual existing values.
        // The sentinel only means "preserve" when the key already exists in $existing.
        foreach ($settings as $key => $value) {
            if ($value === '***' && array_key_exists($key, $existingSettings)) {
                $settings[$key] = $existingSettings[$key];
            }
        }

        $merged    = array_merge($existingSettings, $settings);
        // Filter out any remaining '***' sentinels before saving (only for password fields)
        $merged    = $this->crypto->filterSettings($toolClass, $merged);
        $encrypted = $this->crypto->encryptSettings($toolClass, $merged);

        $record = ToolUserSetting::where('user_id', $userId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($record !== null) {
            Capsule::table('tool_user_settings')
                ->where('user_id', $userId)
                ->where('tool_class', $toolClass)
                ->update([
                    'settings'   => $encrypted,
                    'updated_at' => date(self::DATETIME_FORMAT),
                ]);
        } else {
            Capsule::table('tool_user_settings')->insert([
                'user_id'    => $userId,
                'tool_class' => $toolClass,
                'settings'   => $encrypted,
                'created_at' => date(self::DATETIME_FORMAT),
                'updated_at' => date(self::DATETIME_FORMAT),
            ]);
        }

        return $this->crypto->decodeSettings($toolClass, $encrypted);
    }

    /**
     * Return effective settings: global defaults merged with user settings and agent-specific overrides.
     * Cascade: schema defaults → global settings → user settings → agent overrides.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveSettings(string $toolClass, int $agentId, ?int $userId = null): array
    {
        $merged = $this->getGlobalSettings($toolClass);

        // Merge user settings if userId is provided
        if ($userId !== null) {
            $userSettings = $this->getUserSettings($toolClass, $userId);
            foreach ($userSettings as $key => $value) {
                $merged[$key] = $value;
            }
        }

        $override = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($override !== null) {
            $overrideSettings = $this->crypto->decodeSettings(
                $toolClass,
                $override->getRawOriginal('settings'),
            );

            foreach ($overrideSettings as $key => $value) {
                $merged[$key] = $value;
            }
        }

        // Fill in schema defaults where nothing is set yet
        $defaults = $this->schema->getSchemaDefaults($toolClass);
        foreach ($defaults as $key => $defaultValue) {
            if (!isset($merged[$key])) {
                $merged[$key] = $defaultValue;
            }
        }

        // Multi-select values travel through the form as JSON-encoded strings
        // (the form layer is Record<string, string>), so the cryptographer
        // round-trips them as literal strings. Decode them back to arrays so
        // tool `execute()` and the LLM-facing projection see a list<int>.
        return $this->schema->normalizeMultiSelectValues($toolClass, $merged);
    }

    /**
     * Return a copy of settings with password fields replaced by "***".
     * Null/empty password fields are left as-is.
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function maskForApi(array $settings, string $toolClass): array
    {
        return $this->schema->maskForApi($settings, $toolClass);
    }

    /**
     * Persist agent-specific overrides.
     * Settings are merged with existing stored values; null/empty values break inheritance.
     */
    public function putAgentOverride(string $toolClass, int $agentId, array $settings): void
    {
        $existing = $this->getRawAgentOverride($toolClass, $agentId);
        $agentSettings = [];

        foreach ($settings as $key => $value) {
            if ($value === '***' && array_key_exists($key, $existing)) {
                $value = $existing[$key];
            }
            $agentSettings[$key] = $value;
        }

        // Merge with existing stored settings so omitted fields are preserved.
        $merged = array_merge($existing, $agentSettings);

        // Filter: remove '***' sentinel (only for password fields), null, and empty strings (they mean "use parent")
        $filtered = $this->crypto->filterSettings($toolClass, $merged);
        $filtered = array_filter($filtered, fn($v) => $v !== null && $v !== '');

        $encrypted = $this->crypto->encryptSettings($toolClass, $filtered);

        $existingRecord = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($existingRecord !== null) {
            Capsule::table('agent_tool_overrides')
                ->where('agent_id', $agentId)
                ->where('tool_class', $toolClass)
                ->update([
                    'settings'   => $encrypted,
                    'updated_at' => date(self::DATETIME_FORMAT),
                ]);
        } else {
            Capsule::table('agent_tool_overrides')->insert([
                'agent_id'   => $agentId,
                'tool_class' => $toolClass,
                'settings'   => $encrypted,
                'created_at' => date(self::DATETIME_FORMAT),
                'updated_at' => date(self::DATETIME_FORMAT),
            ]);
        }
    }

    /**
     * Delete the agent-specific override for a tool.
     */
    public function deleteAgentOverride(string $toolClass, int $agentId): void
    {
        AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->delete();
    }

    /**
     * Delete global settings for a tool class.
     */
    public function deleteGlobalSettings(string $toolClass): void
    {
        ToolConfiguration::where('tool_class', $toolClass)->delete();
    }

    /**
     * Delete user-specific settings for a tool class.
     */
    public function deleteUserSettings(string $toolClass, int $userId): void
    {
        ToolUserSetting::where('user_id', $userId)
            ->where('tool_class', $toolClass)
            ->delete();
    }

    /**
     * Encrypt a settings array to a storage string.
     * Only password fields are encrypted per-field; all other fields are stored as plain JSON.
     *
     * @param array<string, mixed> $settings
     */
    public function encryptSettings(string $toolClass, array $settings): string
    {
        return $this->crypto->encryptSettings($toolClass, $settings);
    }

    /**
     * Decrypt a storage string back to a plain settings array.
     *
     * @return array<string, mixed>
     */
    public function decryptSettings(string $storageString): array
    {
        return $this->crypto->decryptSettings($storageString);
    }

    /**
     * Resolve a tool identifier (from #[Tool(name:)]) to its fully-qualified PHP class name.
     */
    public function resolveToolClass(string $toolName): ?string
    {
        return $this->nameResolver->resolveToolClass($toolName);
    }

    /**
     * Return all registered tool class names.
     *
     * @return list<string>
     */
    public function getRegisteredToolClasses(): array
    {
        return $this->nameResolver->getRegisteredToolClasses();
    }

    /**
     * Return schema defaults as key => default_value for all #[ToolSetting] fields.
     * Used to pre-seed agent overrides when enabling a tool.
     *
     * @return array<string, mixed>
     */
    public function getSchemaDefaults(string $toolClass): array
    {
        return $this->schema->getSchemaDefaults($toolClass);
    }

    /**
     * Return keys of required settings that have no value (null or empty) in the given effective settings.
     *
     * @param  array<string, mixed> $effectiveSettings
     * @return list<string>
     */
    public function getMissingRequiredSettings(string $toolClass, array $effectiveSettings): array
    {
        return $this->schema->getMissingRequiredSettings($toolClass, $effectiveSettings);
    }

    /**
     * Return effective settings annotated with their source ('global', 'agent', or 'default').
     * Used by the frontend to show (global) / (local) badges per field.
     *
     * @return array<string, array{value: mixed, source: 'global'|'user'|'agent'|'default'}>
     */
    public function getEffectiveSettingsWithSource(string $toolClass, int $agentId): array
    {
        $global = $this->getGlobalSettings($toolClass);
        $result = [];

        // Seed from global defaults
        foreach ($global as $key => $value) {
            $result[$key] = ['value' => $value, 'source' => 'global'];
        }

        // Merge user-specific settings (per-user overrides)
        $agent = Agent::find($agentId);
        if ($agent !== null) {
            $userSettings = $this->getUserSettings($toolClass, $agent->user_id);
            foreach ($userSettings as $key => $value) {
                $result[$key] = ['value' => $value, 'source' => 'user'];
            }
        }

        $override = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($override !== null) {
            $overrideSettings = $this->crypto->decodeSettings(
                $toolClass,
                $override->getRawOriginal('settings'),
            );

            foreach ($overrideSettings as $key => $value) {
                $result[$key] = ['value' => $value, 'source' => 'agent'];
            }
        }

        // Fill in schema defaults where nothing is set yet
        $defaults = $this->schema->getSchemaDefaults($toolClass);
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = ['value' => $defaultValue, 'source' => 'default'];
            }
        }

        return $result;
    }

    /**
     * Return only the raw agent override (without merging global defaults).
     * Used by the frontend to show which fields are actually stored in the override.
     *
     * @return array<string, mixed>
     */
    public function getRawAgentOverride(string $toolClass, int $agentId): array
    {
        $override = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($override === null) {
            return [];
        }

        return $this->crypto->decodeSettings($toolClass, $override->getRawOriginal('settings'));
    }

    /**
     * Return effective settings filtered to only those with exposeToLlm === true.
     * Each entry includes the human-readable label and the resolved value.
     *
     * @return array<string, array{label: string, value: mixed}>
     */
    public function getLlmToolSettings(string $toolClass, int $agentId, ?int $userId = null): array
    {
        $effective = $this->getEffectiveSettings($toolClass, $agentId, $userId);

        return $this->schema->getLlmToolSettings($toolClass, $effective, $userId);
    }
}
