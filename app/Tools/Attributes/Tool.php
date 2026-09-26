<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a class as a Tool the agent can invoke.
 *
 * Usage:
 *   #[Tool(
 *       name: 'my_tool',
 *       description: 'Does something useful',
 *       displayName: 'My Tool',         // optional
 *       category: 'general',             // optional; defaults to 'general'
 *       icon: 'puzzle',                  // optional; bundled icon key
 *                                        //   (e.g. 'calendar', 'mail', 'search')
 *       recommendsSkills: ['git'],       // optional; slugs the tool bundles —
 *                                        //   enables strict-mode validation
 *                                        //   against SkillScanner on /api/v1/tools
 *   )]
 *   final class MyTool implements ToolInterface { ... }
 *
 * Pass `icon: ''` to explicitly fall through to the plugin / default layer.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Tool
{
    private const NAME_REGEX = '/^[a-z][a-z0-9_]*$/';

    /**
     * Agentskills.io slug rule: 1-64 lowercase alphanumeric + hyphen, no
     * leading/trailing hyphen, no consecutive hyphens. Mirrors the rule
     * enforced by {@see \Spora\Skills\SkillValidator}, so a tool's
     * `recommendsSkills` slug and the matching on-disk skill directory
     * share the same shape.
     */
    private const SLUG_REGEX = '/^(?![a-z0-9-]*--)[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/';

    /**
     * Public read-only view of the declared skill slugs. Null on the wire
     * (constructor default) is normalised to an empty list so callers can
     * iterate without a null guard.
     *
     * @var list<string>
     */
    public readonly array $recommendsSkills;

    public function __construct(
        /** snake_case, e.g. "tavily_search" — must match /^[a-z][a-z0-9_]*$/ */
        public readonly string $name,
        /** Sent to LLM as function description */
        public readonly string $description,
        /** Human-readable name for UI display, e.g. "Tavily Search". Falls back to name if omitted. */
        public readonly ?string $displayName = null,
        /** Category for grouping tools in the Settings UI, e.g. "research", "communication". Falls back to "general". */
        public readonly string $category = 'general',
        /**
         * Bundled-icon key (or full SVG / raw path) for the dashboard's tool row.
         * Optional. Resolution chain (see {@see \Spora\Services\ToolIconResolver}):
         *   1. tool.icon (this attribute — most specific)
         *   2. owning plugin's plugin.json icon field (per-plugin default)
         *   3. null on the wire; frontend's <Icon> component falls back to 'puzzle'.
         *
         * Same surface as plugin.json's icon field — accepts bundled names
         * (e.g. 'calendar', 'mail', 'search'), full <svg> strings, or raw
         * path 'd:' strings.
         */
        public readonly ?string $icon = null,
        /**
         * Slugs of skills the tool bundles. The frontend will offer to
         * enable SkillTool and seed its `allowed_skills` when the operator
         * activates this tool. Validation:
         *   - `null` and `[]` are equivalent (no skills bundled).
         *   - Each entry is trimmed; empty entries are skipped.
         *   - Each non-empty entry must match the agentskills.io slug regex
         *     (see {@see SLUG_REGEX}); mismatches throw InvalidArgumentException.
         *   - Non-string entries throw InvalidArgumentException.
         * See {@see \Spora\Services\ToolsRecommendsSkillsValidator} for the
         * strict-mode runtime check that every declared slug exists on disk.
         *
         * @param array<int|string, mixed>|null $recommendsSkills
         */
        ?array $recommendsSkills = null,
    ) {
        if (!preg_match(self::NAME_REGEX, $this->name)) {
            throw new InvalidArgumentException(
                "Tool name '{$this->name}' must match /^[a-z][a-z0-9_]*$/ (snake_case, lowercase alphanumeric + underscore).",
            );
        }

        $this->recommendsSkills = $this->normaliseRecommendsSkills($recommendsSkills);
    }

    /**
     * Read-only access to the normalised list of declared skill slugs.
     * Always returns a (possibly empty) array — `null` on the constructor
     * has already been collapsed to `[]`.
     *
     * @return list<string>
     */
    public function getRecommendsSkills(): array
    {
        return $this->recommendsSkills;
    }

    /**
     * Trim, drop empties, and validate every entry. Throws on the first
     * invalid slug with the offending value and the regex constraint that
     * rejected it so plugin authors see the exact problem at registration
     * time rather than as a runtime 500.
     *
     * @param array<int|string, mixed>|null $raw
     * @return list<string>
     */
    private function normaliseRecommendsSkills(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        $normalised = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException(
                    "Tool '{$this->name}' declares a non-string recommendsSkills entry.",
                );
            }
            $trimmed = trim($entry);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match(self::SLUG_REGEX, $trimmed) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    "Tool '%s' declares recommendsSkills slug '%s' which does not match %s (lowercase alphanumeric + hyphen, 1-64 chars, no leading/trailing hyphen, no consecutive hyphens).",
                    $this->name,
                    $trimmed,
                    self::SLUG_REGEX,
                ));
            }
            $normalised[] = $trimmed;
        }

        return $normalised;
    }
}
