<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionClass;
use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Models\AgentToolOverride;
use Spora\Models\ToolConfiguration;
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
    public function __construct(
        private readonly SecurityManagerInterface $security,
    ) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

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
     * Password fields are encrypted; other fields stored as plain strings.
     */
    public function putGlobalSettings(string $toolClass, array $settings): void
    {
        $encoded  = $this->encodeSettings($toolClass, $settings);
        $toolName = $this->getToolName($toolClass);

        $existing = ToolConfiguration::where('tool_class', $toolClass)->first();

        if ($existing !== null) {
            Capsule::table('tool_configurations')
                ->where('tool_class', $toolClass)
                ->update([
                    'tool_name'  => $toolName,
                    'settings'   => json_encode($encoded),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            Capsule::table('tool_configurations')->insert([
                'tool_class'  => $toolClass,
                'tool_name'   => $toolName,
                'settings'    => json_encode($encoded),
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Return effective settings: global defaults merged with agent-specific overrides.
     * Only `scope: 'agent'` keys from the override are applied.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveSettings(string $toolClass, int $agentId): array
    {
        $merged = $this->getGlobalSettings($toolClass);

        $override = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($override === null) {
            return $merged;
        }

        $overrideSettings = $this->decodeSettings(
            $toolClass,
            $override->getRawOriginal('settings'),
        );

        $scopeMap = $this->getScopeMap($toolClass);

        foreach ($overrideSettings as $key => $value) {
            // Only apply override for keys explicitly scoped to 'agent'
            if (($scopeMap[$key] ?? 'agent') === 'agent') {
                $merged[$key] = $value;
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
     * Only `scope: 'agent'` keys are stored; global-scoped keys are silently discarded.
     */
    public function putAgentOverride(string $toolClass, int $agentId, array $settings): void
    {
        $scopeMap       = $this->getScopeMap($toolClass);
        $agentSettings  = [];

        foreach ($settings as $key => $value) {
            if (($scopeMap[$key] ?? 'agent') === 'agent') {
                $agentSettings[$key] = $value;
            }
        }

        $passwordKeys = $this->getPasswordKeys($toolClass);
        $encoded      = [];

        foreach ($agentSettings as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== null && $value !== '') {
                $encrypted     = $this->security->encrypt((string) $value);
                $encoded[$key] = $encrypted->toStorageString();
            } else {
                $encoded[$key] = $value;
            }
        }

        $existing = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($existing !== null) {
            Capsule::table('agent_tool_overrides')
                ->where('agent_id', $agentId)
                ->where('tool_class', $toolClass)
                ->update([
                    'settings'   => json_encode($encoded),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            Capsule::table('agent_tool_overrides')->insert([
                'agent_id'   => $agentId,
                'tool_class' => $toolClass,
                'settings'   => json_encode($encoded),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
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

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

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
     * Encode settings for DB storage, encrypting password fields.
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function encodeSettings(string $toolClass, array $settings): array
    {
        $passwordKeys = $this->getPasswordKeys($toolClass);
        $encoded      = [];

        foreach ($settings as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== null && $value !== '') {
                $encrypted     = $this->security->encrypt((string) $value);
                $encoded[$key] = $encrypted->toStorageString();
            } else {
                $encoded[$key] = $value;
            }
        }

        return $encoded;
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
     * Return a map of key => scope for all #[ToolSetting] attributes on the class.
     *
     * @return array<string, string>
     */
    private function getScopeMap(string $toolClass): array
    {
        $reflection = new ReflectionClass($toolClass);
        $map        = [];

        foreach ($reflection->getAttributes(ToolSetting::class) as $attribute) {
            /** @var ToolSetting $instance */
            $instance       = $attribute->newInstance();
            $map[$instance->key] = $instance->scope;
        }

        return $map;
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
}
