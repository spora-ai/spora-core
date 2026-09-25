<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Skills\SkillScanner;
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
 * validator scans the {@see SkillScanner} once per call rather than per tool
 * class — the scan is the expensive step, the reflection loop is cheap. The
 * wire-format field name `recommends_skills` is what the SPA renders; the
 * validator's own return shape uses native PHP keys (`tool_class`,
 * `tool_name`, `missing`) since it never crosses the JSON boundary itself.
 */
final class ToolsRecommendsSkillsValidator
{
    public function __construct(
        private readonly ToolConfigNameResolver $resolver,
        private readonly ?SkillScanner $scanner,
    ) {}

    /**
     * @return list<array{tool_class: string, tool_name: string, missing: list<string>}>
     */
    public function validate(): array
    {
        if ($this->scanner === null) {
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
        if (!class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        $attrs      = $reflection->getAttributes(Tool::class);
        if ($attrs === []) {
            return [];
        }

        /** @var Tool $tool */
        $tool = $attrs[0]->newInstance();
        if ($tool->recommendsSkills === []) {
            return [];
        }

        $missing = [];
        foreach ($tool->recommendsSkills as $slug) {
            if (!isset($knownSlugs[strtolower($slug)])) {
                $missing[] = $slug;
            }
        }
        return $missing;
    }

    /**
     * Scan the disk once. Skills whose frontmatter fails validation are
     * still included — the strict-mode check is "does a SKILL.md exist for
     * this slug?", not "is the skill parseable?". Operators with broken
     * skill bodies still want the slug to resolve so the LLM gets a
     * readable error rather than a missing-skill one.
     *
     * @return array<string, true>
     */
    private function knownSlugSet(): array
    {
        $set = [];
        foreach ($this->scanner->scan() as $skill) {
            $set[strtolower($skill->slug())] = true;
        }
        return $set;
    }
}
