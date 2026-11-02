<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use Illuminate\Database\Eloquent\Builder;
use Spora\Auth\ApiKeyScope;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Services\PrincipalResolver;

/**
 * The single answer to "may this key touch that agent?".
 *
 * Every read and write on the scoped surfaces asks this service rather than
 * re-deriving the rule, because the rule is the whole risk of the feature:
 * a group key that could see one agent too many is a breach, and a global
 * key that could see a personal agent is a different one.
 *
 * The reach is expressed as a query rather than as a list of ids, so the
 * global scope does not materialise every group-owned agent on the
 * install before it can answer the first question.
 *
 * Personal agents — the ones a user owns rather than a group — are outside
 * every scope except `agent`. A user-principal agent has no group-principal,
 * so it is in no group's reach, and the global scope only ever resolves
 * group-principals. The only way to reach a personal agent over HTTP is a
 * key minted on that agent.
 */
final class ApiKeyReach
{
    public function __construct(
        private readonly PrincipalResolver $resolver,
    ) {}

    /**
     * The agents this key may touch.
     *
     * @return Builder<Agent>
     */
    public function agents(ApiKeyContext $ctx): Builder
    {
        return match ($ctx->scope) {
            ApiKeyScope::Agent => $this->agentsWithPrincipal($ctx->agentId() === null ? [] : [$ctx->agentId()]),
            ApiKeyScope::Group => $this->agentsWithPrincipal($this->groupPrincipalIds($ctx)),
            ApiKeyScope::Global => Agent::query()->whereIn('principal_id', $this->allGroupPrincipalIds()),
        };
    }

    /**
     * The ids of the agents this key may touch, as a subquery.
     *
     * @return Builder<Agent>
     */
    public function agentIds(ApiKeyContext $ctx): Builder
    {
        return $this->agents($ctx)->select('agents.id');
    }

    /**
     * Whether one agent is inside the reach.
     */
    public function allows(ApiKeyContext $ctx, int $agentId): bool
    {
        return $this->agents($ctx)->where('agents.id', $agentId)->exists();
    }

    /**
     * The agent, when it is inside the reach — and null when it is not,
     * which the callers answer with a 404 rather than a 403 so a caller
     * cannot probe which ids exist.
     */
    public function targetAgent(ApiKeyContext $ctx, int $agentId): ?Agent
    {
        return $this->agents($ctx)->where('agents.id', $agentId)->first();
    }

    /**
     * The user a chat with this agent is attributed to.
     *
     * For the agent scope this is the key's own agent's owner. For the
     * group and global scopes the target agent changes per request, so the
     * owner is resolved from the *target* agent's principal at call time.
     */
    public function ownerUserId(Agent $agent): ?int
    {
        return $this->resolver->ownerUserId((int) $agent->principal_id);
    }

    /**
     * The agents owned by one of the given principals.
     *
     * An empty list yields `0 = 1`, so a key whose group has no principal
     * reaches nothing rather than everything.
     *
     * @param list<int> $principalIds
     * @return Builder<Agent>
     */
    private function agentsWithPrincipal(array $principalIds): Builder
    {
        return Agent::query()->whereIn('principal_id', $principalIds);
    }

    /**
     * The group-principal of the key's group.
     *
     * Null when the group has no principal — a group whose principal row
     * was removed by hand. Such a key reaches nothing.
     */
    private function groupPrincipalIds(ApiKeyContext $ctx): array
    {
        if ($ctx->groupId === null) {
            return [];
        }

        $id = Principal::where('type', Principal::TYPE_GROUP)
            ->where('group_id', $ctx->groupId)
            ->value('id');

        return $id === null ? [] : [(int) $id];
    }

    /**
     * Every group-principal in the install — the global scope's reach.
     */
    private function allGroupPrincipalIds(): Builder
    {
        return Principal::query()->select('id')->where('type', Principal::TYPE_GROUP);
    }
}
