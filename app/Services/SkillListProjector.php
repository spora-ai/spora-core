<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillSummary;

/**
 * Projects an `allowed_skills` setting into the `name: description` strings the
 * model reads in a tool definition.
 *
 * Split out of {@see ToolConfigSchemaInspector} because it is the one setting
 * whose projection is principal-dependent, and keeping it there made the
 * inspector own both the schema reflection and a tenant-boundary decision.
 *
 * The resolution is a **registry lookup, not a stored map**, and that is the
 * whole point: an eager snapshot taken once per process from the filesystem
 * would omit every provider-supplied skill, so such a skill would be
 * authorised by the `skill` tool and simultaneously invisible in the tool
 * definition that would suggest it. The agent could call it; nothing would
 * ever tell it the skill existed. That failure has no error, no log, and no
 * failing test.
 */
final readonly class SkillListProjector
{
    public function __construct(
        private SkillProviderRegistry $skills,
    ) {}

    /**
     * @param  mixed $value raw `allowed_skills` value
     * @return list<string>
     */
    public function project(mixed $value, ?PrincipalContext $context): array
    {
        if (!is_array($value)) {
            return [];
        }

        $byName = $this->visibleByName($context);

        $out = [];
        foreach ($value as $name) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            $skill = $byName[$name] ?? null;
            $out[] = $skill === null
                ? "(unavailable: {$name})"
                : self::describe($skill);
        }

        return $out;
    }

    /**
     * The skills this execution's principal can see, keyed by name.
     *
     * A null or unresolvable principal resolves **nothing** principal-scoped.
     * Operator-default and template previews call this path with no principal
     * in scope, and widening there would put one tenant's skills in another's
     * preview. Shipped skills are principal-independent and still resolve.
     *
     * @return array<string, SkillSummary>
     */
    private function visibleByName(?PrincipalContext $context): array
    {
        $principalId = $context !== null && $context->isResolvable() ? $context->principalId : null;

        $byName = [];
        foreach ($this->skills->getSkills($principalId) as $summary) {
            $byName[$summary->name] ??= $summary;
        }

        return $byName;
    }

    private static function describe(SkillSummary $skill): string
    {
        $description = mb_strlen($skill->description) > 80
            ? mb_substr($skill->description, 0, 77) . '...'
            : $skill->description;

        return $description === ''
            ? $skill->name
            : "{$skill->name}: {$description}";
    }
}
