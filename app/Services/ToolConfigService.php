<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Models\Agent;
use Spora\Models\AgentToolOverride;
use Spora\Models\ToolConfiguration;
use Spora\Models\ToolUserSetting;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;

/**
 * The ONLY class permitted to read or write tool_configurations.settings
 * and agent_tool_overrides.settings columns.
 *
 * The Eloquent models have a guard accessor that throws LogicException on direct
 * `settings` access — all reads/writes must funnel through this service.
 */
class ToolConfigService
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** @var array<string, string> tool name (from #[Tool(name:)]) => fully-qualified class name */
    private ?array $toolNameMap = null;

    /** @var list<string> */
    private readonly array $toolClasses;

    private readonly SecurityManagerInterface $security;

    private readonly LoggerInterface $logger;

    /**
     * @param list<string> $toolClasses
     */
    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $toolClasses = [],
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->toolClasses = $toolClasses;
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

        return $this->decodeSettings($toolClass, $model->getRawOriginal('settings'));
    }

    /**
     * Persist global settings for a tool class.
     * Settings are wholesale-encrypted; omitted keys are merged from existing stored values.
     */
    public function putGlobalSettings(string $toolClass, array $settings): void
    {
        $toolName = $this->getToolName($toolClass);

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
        $merged   = $this->filterSettings($toolClass, $merged);

        $encrypted = $this->encryptSettings($toolClass, $merged);

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

        return $this->decodeSettings($toolClass, $model->getRawOriginal('settings'));
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
        $merged    = $this->filterSettings($toolClass, $merged);
        $encrypted = $this->encryptSettings($toolClass, $merged);

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

        return $this->decodeSettings($toolClass, $encrypted);
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
            $overrideSettings = $this->decodeSettings(
                $toolClass,
                $override->getRawOriginal('settings'),
            );

            foreach ($overrideSettings as $key => $value) {
                $merged[$key] = $value;
            }
        }

        // Fill in schema defaults where nothing is set yet
        $defaults = $this->getSchemaDefaults($toolClass);
        foreach ($defaults as $key => $defaultValue) {
            if (!isset($merged[$key])) {
                $merged[$key] = $defaultValue;
            }
        }

        return $merged;
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
        $passwordKeys = $this->getPasswordKeys($toolClass);
        $masked       = $settings;

        foreach ($passwordKeys as $key) {
            if (array_key_exists($key, $masked) && $masked[$key] !== null && $masked[$key] !== '') {
                $masked[$key] = '***';
            }
        }

        return $masked;
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
        $filtered = $this->filterSettings($toolClass, $merged);
        $filtered = array_filter($filtered, fn($v) => $v !== null && $v !== '');

        $encrypted = $this->encryptSettings($toolClass, $filtered);

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
     * Decode raw JSON from DB, decrypting password fields.
     * Returns [] for null/empty input.
     * DecryptionFailedException is caught per-field: that field becomes null.
     *
     * @return array<string, mixed>
     */
    private function decodeSettings(string $toolClass, ?string $rawJson): array
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
        $passwordKeys = $this->getPasswordKeys($toolClass);
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

        $passwordKeys = $this->getPasswordKeys($toolClass);
        $result       = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== null && $value !== '') {
                try {
                    $result[$key] = $this->security->decrypt(new EncryptedValue((string) $value));
                } catch (DecryptionFailedException) {
                    error_log("ToolConfigService: decryption failed for field {$key} of {$toolClass}");
                    $result[$key] = null;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Filter settings: remove fields set to the "***" sentinel (preserve-existing marker).
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function filterSettings(string $toolClass, array $settings): array
    {
        $passwordKeys = $this->getPasswordKeys($toolClass);
        return array_filter(
            $settings,
            fn($v, $k) => !($v === '***' && in_array($k, $passwordKeys, true)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Return keys of all #[ToolSetting] attributes where type === 'password'.
     *
     * @return list<string>
     */
    private function getPasswordKeys(string $toolClass): array
    {
        $reflection = new ReflectionClass($toolClass);
        $keys       = [];

        foreach ($reflection->getAttributes(ToolSetting::class) as $attribute) {
            /** @var ToolSetting $instance */
            $instance = $attribute->newInstance();

            if ($instance->type === 'password') {
                $keys[] = $instance->key;
            }
        }

        return $keys;
    }

    /**
     * Extract the tool name from the #[Tool] attribute on the class.
     * Falls back to the short class name if the attribute is absent.
     */
    private function getToolName(string $toolClass): string
    {
        $reflection = new ReflectionClass($toolClass);
        $attrs      = $reflection->getAttributes(Tool::class);

        if ($attrs !== []) {
            /** @var Tool $tool */
            $tool = $attrs[0]->newInstance();

            return $tool->name;
        }

        return $reflection->getShortName();
    }

    /**
     * Build the tool name → class map from registered tool classes.
     *
     * @return array<string, string>
     */
    private function buildToolNameMap(): array
    {
        if ($this->toolNameMap !== null) {
            return $this->toolNameMap;
        }

        $this->toolNameMap = [];
        foreach ($this->toolClasses as $class) {
            if (!class_exists($class)) {
                $this->logger->warning('buildToolNameMap: skipping non-existent class', ['class' => $class]);
                continue;
            }
            $reflection = new ReflectionClass($class);
            $attrs = $reflection->getAttributes(Tool::class);
            if ($attrs !== []) {
                /** @var Tool $tool */
                $tool = $attrs[0]->newInstance();
                $this->toolNameMap[$tool->name] = $class;
            }
        }

        return $this->toolNameMap;
    }

    /**
     * Resolve a tool identifier (from #[Tool(name:)]) to its fully-qualified PHP class name.
     */
    public function resolveToolClass(string $toolName): ?string
    {
        return $this->buildToolNameMap()[$toolName] ?? null;
    }

    /**
     * Return all registered tool class names.
     *
     * @return list<string>
     */
    public function getRegisteredToolClasses(): array
    {
        return $this->toolClasses;
    }

    /**
     * Return schema defaults as key => default_value for all #[ToolSetting] fields.
     * Used to pre-seed agent overrides when enabling a tool.
     *
     * @return array<string, mixed>
     */
    public function getSchemaDefaults(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $defaults = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            if ($setting->default !== null) {
                $defaults[$setting->key] = $setting->default;
            }
        }

        return $defaults;
    }

    /**
     * Return keys of required settings that have no value (null or empty) in the given effective settings.
     *
     * @param  array<string, mixed> $effectiveSettings
     * @return list<string>
     */
    public function getMissingRequiredSettings(string $toolClass, array $effectiveSettings): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $missing = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            if ($setting->required) {
                $value = $effectiveSettings[$setting->key] ?? null;
                if ($value === null || $value === '') {
                    $missing[] = $setting->key;
                }
            }
        }

        return $missing;
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
            $overrideSettings = $this->decodeSettings(
                $toolClass,
                $override->getRawOriginal('settings'),
            );

            foreach ($overrideSettings as $key => $value) {
                $result[$key] = ['value' => $value, 'source' => 'agent'];
            }
        }

        // Fill in schema defaults where nothing is set yet
        $defaults = $this->getSchemaDefaults($toolClass);
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

        return $this->decodeSettings($toolClass, $override->getRawOriginal('settings'));
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
        $labels = $this->getLlmSettingLabels($toolClass);

        $result = [];
        foreach ($labels as $key => $label) {
            $result[$key] = [
                'label' => $label,
                'value' => $effective[$key] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Return key => label map for all #[ToolSetting] fields where exposeToLlm === true.
     *
     * @return array<string, string>
     */
    private function getLlmSettingLabels(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $labels = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            if ($setting->exposeToLlm) {
                $labels[$setting->key] = $setting->label;
            }
        }

        return $labels;
    }
}
