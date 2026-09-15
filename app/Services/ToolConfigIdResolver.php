<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\ToolConfiguration;
use Spora\Models\ToolUserSetting;

/**
 * Thin id lookups against the tool_configurations / tool_user_settings
 * tables. Lives in its own class so {@see ToolConfigService} stays
 * under the SonarCloud S1448 20-method ceiling — the rest of the
 * surface is CRUD + encryption + schema, which is plenty.
 *
 * Not declared `final` so Mockery can construct partial mocks in
 * tests (mirrors the rationale on {@see ToolConfigService}).
 */
class ToolConfigIdResolver
{
    /**
     * Return the row id of the global settings row for a tool class,
     * or null when no global row exists.
     */
    public function globalConfigId(string $toolClass): ?int
    {
        $id = ToolConfiguration::where('tool_class', $toolClass)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Return the row id of the principal-scoped settings for the
     * (toolClass, principalId) pair, or null when no row exists.
     */
    public function principalSettingsId(string $toolClass, int $principalId): ?int
    {
        $id = ToolUserSetting::where('principal_id', $principalId)
            ->where('tool_class', $toolClass)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
