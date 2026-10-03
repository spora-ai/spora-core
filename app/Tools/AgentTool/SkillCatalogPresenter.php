<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\SkillTool;

/**
 * The `skills` block of `get_available_tools` — what the agent may load, and
 * what it could ask for.
 *
 * Two lists, deliberately not one. `allowed` is this agent's own
 * `allowed_skills`; `visible` is every skill the *executing principal* can
 * see. Folding them together would put names in the same array whether or not
 * the agent holds them, and the model would read the membership of the array
 * as the answer to "may I load this?".
 *
 * This is the read half of what the `skill` tool's `list` operation used to
 * do. It lives here because the answer is about the agent's configuration
 * rather than about a skill's content, which is the same reason the write
 * moved to `configure_tools`.
 */
final class SkillCatalogPresenter
{
    public function __construct(
        private readonly SkillProviderRegistry $skills,
        private readonly ToolConfigServiceInterface $config,
        private readonly ?PrincipalResolver $principals = null,
    ) {}

    /**
     * @return array{allowed: list<string>, visible: list<array{name: string, description: string, active: bool}>}
     */
    public function present(int $agentId, ?int $userId, ?PrincipalContext $context): array
    {
        $allowed = self::allowedNames(
            $this->config->getEffectiveSettings(SkillTool::class, $agentId, $userId, $context)['allowed_skills'] ?? [],
        );

        $visible = [];
        foreach ($this->skills->getSkills(self::principalId($agentId, $context, $this->principals)) as $summary) {
            $visible[] = [
                'name'        => $summary->name,
                'description' => $summary->description,
                'active'      => in_array($summary->name, $allowed, true),
            ];
        }

        return ['allowed' => $allowed, 'visible' => $visible];
    }

    /**
     * The allowlist as bare names, ready for an `in_array` against the
     * listing. `getEffectiveSettings()` already normalises a
     * `resolveAs: 'skill'` multi-select to a validated `list<string>`; this
     * only gives the type system something it can prove.
     *
     * @return list<string>
     */
    private static function allowedNames(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The principal whose skills this call may see — the same resolution, for
     * the same reason, as `SkillTool::resolvePrincipalId()` and
     * `AgentTool::executingPrincipalId()`. The execution's context, else the
     * agent's own principal; never the runner, and an unresolvable principal
     * becomes `null` so a provider fails closed.
     */
    private static function principalId(int $agentId, ?PrincipalContext $context, ?PrincipalResolver $principals): ?int
    {
        $resolved = $context ?? ($principals ?? new PrincipalResolver())->resolveForToolExecute($agentId);

        return $resolved->isResolvable() ? $resolved->principalId : null;
    }
}
