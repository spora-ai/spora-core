<?php

declare(strict_types=1);

namespace Spora\Tools\SkillTool;

use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\SkillTool;

/**
 * An agent's own `allowed_skills`, as read and written.
 *
 * Split out of {@see SkillTool} because it is a different responsibility from
 * reading a skill: this is the *agent's configuration*, and the tool only borrows
 * it. It also kept the class over the method-count limit the quality gate
 * enforces, which is a fair signal that a class holding both halves of the
 * `skill` tool was two classes wearing one name.
 *
 * The setting belongs to the agent, so every read goes through the execution's
 * context rather than the runner's: omitting it lets the cascade fall back to the
 * runner's principals, which is a different set — a group agent's group-level
 * `allowed_skills` would be invisible, and a scheduled run with no runner would
 * resolve nothing at all.
 */
final class AgentSkillAllowlist
{
    public function __construct(
        private readonly ToolConfigServiceInterface $config,
    ) {}

    /**
     * The effective allowlist as lower-cased names.
     *
     * `isSkillAllowed()` in the tool does its own array walk, so the two readers of
     * this setting have to agree on what an entry is: a hand-edited override can hold
     * anything, and `strtolower` on a non-string is a TypeError rather than a skip.
     *
     * @return list<string>
     */
    public function names(int $agentId, ?int $userId, ?PrincipalContext $context): array
    {
        $settings = $this->config->getEffectiveSettings(SkillTool::class, $agentId, $userId, $context);
        $allowed = $settings['allowed_skills'] ?? [];

        if (is_string($allowed)) {
            // The multi-select is stored JSON-encoded, and the override endpoint
            // takes it that way, so a string here is the normal case, not a smell.
            $decoded = json_decode($allowed, true);
            $allowed = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($allowed)) {
            return [];
        }

        $names = [];
        foreach ($allowed as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $names[] = strtolower(trim($candidate));
            }
        }

        return $names;
    }

    /**
     * Whether `name` is one this principal could be given by hand.
     *
     * The check `activate` makes before writing: it can only ever pre-approve
     * something the operator could have approved anyway, so it is not a way to reach
     * another tenant's skills. A name that resolves to nothing is refused rather
     * than left in the allowlist for the model to load and fail on with a much less
     * obvious message.
     */
    public function isActivatable(
        string $name,
        SkillProviderRegistry $skills,
        ?int $principalId,
    ): bool {
        if ($name === '') {
            return false;
        }

        return $skills->getSkillDetails($name, $principalId) !== null;
    }

    /**
     * Append `name` to the agent's allowlist and return the new list.
     *
     * Read-modify-written through the service rather than replaced, because a
     * single agent override row holds every setting for the tool: writing just the
     * allowlist would drop the rest. The caller owns the "is it already there?"
     * question, so this never makes a no-op write.
     *
     * @param  list<string>  $current
     * @return list<string>
     */
    public function add(string $name, int $agentId, array $current): array
    {
        $next = [...$current, $name];
        $this->config->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => json_encode($next)]);

        return $next;
    }
}
