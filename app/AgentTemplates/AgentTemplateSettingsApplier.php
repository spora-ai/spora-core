<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ToolSettingSchema;

/**
 * Applies a `tools[].settings` block from an agent template to the
 * `agent_tool_overrides` table via {@see \Spora\Services\ToolConfigService::putAgentOverride()}.
 *
 * Extracted from {@see AgentTemplateImporter} so the importer stays under the
 * SonarCloud 20-method-per-class ceiling (S1448). The applier owns the
 * schema-aware coercion rules:
 *
 * - Skip keys the tool does not declare (defence-in-depth — the validator
 *   already rejects them, but never trust the file).
 * - Skip keys whose schema type is `password` (validator-enforced; never
 *   should happen here, but no surprises if it does).
 * - Coerce `multi-select` arrays to JSON strings (the form layer's shape)
 *   so {@see \Spora\Services\ToolConfigService::putAgentOverride()} can
 *   round-trip them. For `resolveAs: 'skill'` settings, intersect the
 *   import list against the {@see SkillProviderRegistry} and emit
 *   `SKILL_MISSING` warnings for dropped slugs.
 *
 * **The registry, not the scanner.** Availability is a property of every skill
 * *provider*, not of the shipped directories: `spora-plugin-custom-skills`
 * serves database-backed skills that never touch `SkillScanner`. Asking the
 * scanner meant a template granting one of those lost it on import with a
 * `SKILL_MISSING` warning naming a skill that was in fact installed — and the
 * export → import round-trip silently shed `allowed_skills` for anyone
 * shipping such a template.
 */
final class AgentTemplateSettingsApplier
{
    public function __construct(
        private readonly \Spora\Services\ToolConfigService $toolConfig,
        private readonly ?SkillProviderRegistry $skills = null,
    ) {}

    /**
     * @param array<string, mixed> $settings
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     * @return int Number of keys actually written to the override row.
     */
    public function apply(int $agentId, string $toolClass, array $settings, int $toolIndex, array &$warnings): int
    {
        $schema = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            $schema[$setting->key] = $setting;
        }

        $collected = [];
        foreach ($settings as $key => $value) {
            $setting = $schema[$key] ?? null;
            if (!$setting instanceof ToolSetting || $setting->type === 'password') {
                continue;
            }
            if ($setting->type === 'multi-select') {
                $value = $this->prepareMultiSelectSetting($setting, $value, $toolIndex, $warnings);
            }
            $collected[$key] = $value;
        }

        if ($collected !== []) {
            $this->toolConfig->putAgentOverride($toolClass, $agentId, $collected);
        }
        return count($collected);
    }

    /**
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     */
    private function prepareMultiSelectSetting(
        ToolSetting $setting,
        mixed $value,
        int $toolIndex,
        array &$warnings,
    ): string {
        $items = is_array($value) ? array_values($value) : [];
        if ($setting->resolveAs === 'skill' && $this->skills !== null) {
            $items = $this->filterMissingSkills($items, $setting->key, $toolIndex, $warnings);
        }
        return json_encode($items, JSON_THROW_ON_ERROR);
    }

    /**
     * Drop every slug no provider serves, reporting each.
     *
     * Both halves of the identity count, because `allowed_skills` entries are
     * read back as names everywhere else ({@see \Spora\Services\SkillListProjector})
     * while a shipped skill's slug is its directory basename. A provider with
     * no directory behind it carries only a name.
     *
     * `null` for the principal, so a provider-scoped skill cannot be granted by
     * a template: the import runs as no one in particular, and a grant that
     * depended on who imported it would not survive the round-trip — the same
     * rule {@see \Spora\Services\ToolsRecommendsSkillsValidator} holds to. A
     * null registry performs no check at all rather than dropping everything:
     * the property is optional for test and build-time construction, and
     * emptying every imported agent's allowlist because a wiring is absent is
     * the more damaging failure.
     *
     * @param list<mixed> $items
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     * @return list<mixed>
     */
    private function filterMissingSkills(array $items, string $key, int $toolIndex, array &$warnings): array
    {
        $available = [];
        foreach ($this->skills?->getSkills(null) ?? [] as $skill) {
            $available[strtolower($skill->name)] = true;
            if ($skill->slug !== null && $skill->slug !== '') {
                $available[strtolower($skill->slug)] = true;
            }
        }

        $filtered = [];
        foreach ($items as $slug) {
            if (is_string($slug) && isset($available[strtolower(trim($slug))])) {
                $filtered[] = $slug;
                continue;
            }
            $warnings[] = [
                'code' => 'SKILL_MISSING',
                'severity' => 'warning',
                'message' => sprintf("Skill '%s' is not available locally and was dropped.", (string) $slug),
                'path' => "tools[{$toolIndex}].settings.{$key}",
            ];
        }
        return $filtered;
    }
}
