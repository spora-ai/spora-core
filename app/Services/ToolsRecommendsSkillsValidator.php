<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\Attributes\Tool;

/**
 * Finds tools whose `#[Tool(recommendsSkills: ...)]` declarations name skill
 * slugs that are NOT present on disk.
 *
 * Strict mode: the HTTP list endpoint ({@see \Spora\Http\ToolController::index()})
 * treats any non-empty result as a 500 with code `TOOLS_RECOMMENDS_SKILLS_MISSING`.
 * That trade-off is intentional — a misconfigured plugin (declared slug, no
 * shipped skill) is a packaging bug operators must see rather than a silently
 * empty allowlist. Plugin authors are expected to ship their own
 * build-time test that mirrors {@see \Tests\Unit\Tools\ToolRecommendsSkillsValidationCoreTest}
 * for their scanner roots.
 *
 * Comparison is case-insensitive (the scanner slug is canonical), and the
 * validator lists the skills once per call rather than per tool class — the
 * listing is the expensive step, the reflection loop is cheap. The
 * wire-format field name `recommends_skills` is what the SPA renders; the
 * validator's own return shape uses native PHP keys (`tool_class`,
 * `tool_name`, `missing`) since it never crosses the JSON boundary itself.
 *
 * **It lists with a `null` principal, deliberately.** `recommendsSkills` is a
 * static declaration on a tool class, so it has no principal to resolve
 * against, and a strict-mode check that 500s the whole tool list must not
 * depend on who is asking. A principal-scoped provider therefore returns `[]`
 * here, which means a tool may not advertise a custom skill: the declaration
 * would be true for one user and false for the next. Shipped skills are
 * principal-independent and still resolve.
 */
final class ToolsRecommendsSkillsValidator
{
    public function __construct(
        private readonly ToolConfigNameResolver $resolver,
        private readonly ?SkillProviderRegistry $skills,
    ) {}

    /**
     * @return list<array{tool_class: string, tool_name: string, missing: list<string>}>
     */
    public function validate(): array
    {
        if ($this->skills === null) {
            return [];
        }

        $knownSlugs = $this->knownSlugSet();

        $violations = [];
        foreach ($this->resolver->getRegisteredToolClasses() as $class) {
            $missing = $this->missingSlugsFor($class, $knownSlugs);
            if ($missing === []) {
                continue;
            }
            $violations[] = [
                'tool_class' => $class,
                'tool_name'  => $this->resolver->getToolName($class),
                'missing'    => $missing,
            ];
        }

        return $violations;
    }

    /**
     * @param array<string, true> $knownSlugs  lowercased slug set, pre-computed once per validate() call.
     * @return list<string>
     */
    private function missingSlugsFor(string $class, array $knownSlugs): array
    {
        $missing = [];
        if (class_exists($class)) {
            $reflection = new ReflectionClass($class);
            $attrs      = $reflection->getAttributes(Tool::class);
            if ($attrs !== []) {
                /** @var Tool $tool */
                $tool = $attrs[0]->newInstance();
                foreach ($tool->recommendsSkills as $slug) {
                    if (!isset($knownSlugs[strtolower($slug)])) {
                        $missing[] = $slug;
                    }
                }
            }
        }
        return $missing;
    }

    /**
     * List the skills once. Skills whose frontmatter fails validation are
     * still included — the strict-mode check is "does a SKILL.md exist for
     * this slug?", not "is the skill parseable?". Operators with broken
     * skill bodies still want the slug to resolve so the LLM gets a
     * readable error rather than a missing-skill one.
     *
     * Keyed on the slug, falling back to the name: a shipped skill's slug is
     * its directory basename, which is what `recommendsSkills` has always been
     * compared against, and a provider with no directory behind it only has a
     * name.
     *
     * @return array<string, true>
     */
    private function knownSlugSet(): array
    {
        $set = [];
        foreach ($this->skills->getSkills(null) as $summary) {
            $set[strtolower($summary->slug ?? $summary->name)] = true;
        }
        return $set;
    }
}
