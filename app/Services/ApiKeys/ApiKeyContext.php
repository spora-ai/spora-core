<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use LogicException;
use Spora\Auth\ApiKeyScope;
use Spora\Http\Exceptions\ApiKeyContextException;
use Spora\Models\Agent;
use Spora\Models\ApiKey;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * The authenticated caller behind one API request.
 *
 * Read off the request rather than injected: `Router::invokeController()`
 * matches controller parameters against the route's path variables only,
 * so a value the middleware puts in `$request->attributes` is invisible to
 * a typed parameter. The Pest helper `callController()` *does* read
 * attributes, so injecting these would pass every test and fail in
 * production.
 *
 * What the context carries depends on the scope:
 *
 *   - `agent`  — the one agent the key may reach, and the user its chats
 *     are attributed to.
 *   - `group`  — the one group the key may reach.
 *   - `global` — neither; the reach is the whole install's groups.
 */
final class ApiKeyContext
{
    public function __construct(
        public readonly ApiKey $key,
        public readonly ApiKeyScope $scope,
        public readonly ?Agent $agent = null,
        public readonly ?int $ownerUserId = null,
        public readonly ?int $groupId = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        /** @var self|null $context */
        $context = $request->attributes->get('api_key_context');

        if (!$context instanceof self) {
            throw new LogicException('ApiKeyContext::fromRequest called outside an API key middleware.');
        }

        return $context;
    }

    /**
     * `ownerUserId` is the user id Spora attributes the chat to, written
     * to `tasks.trigger_user_id`. A group-owned agent resolves to the group
     * owner rather than to any member, so the attribution is stable
     * regardless of who happened to mint the key.
     */
    public static function forAgent(ApiKey $key, Agent $agent, PrincipalResolver $resolver): self
    {
        $ownerUserId = $resolver->ownerUserId((int) $agent->principal_id);

        if ($ownerUserId === null) {
            throw new ApiKeyContextException('Agent has no resolvable owner principal.');
        }

        return new self($key, ApiKeyScope::Agent, $agent, $ownerUserId);
    }

    public function agentId(): ?int
    {
        return $this->agent?->id === null ? null : (int) $this->agent->id;
    }

    /**
     * The one agent the key reaches.
     *
     * Only the agent scope has one, and its middleware guarantees it — so a
     * null here is a broken invariant rather than something a caller can
     * cause, and it fails loudly instead of quietly answering with nothing.
     */
    public function requireAgent(): Agent
    {
        return $this->agent ?? throw new LogicException('This key is not agent-scoped.');
    }

    /**
     * The id of the one agent the key reaches.
     */
    public function requireAgentId(): int
    {
        return (int) $this->requireAgent()->getKey();
    }

    /**
     * The user the key's chats are attributed to.
     */
    public function requireOwnerUserId(): int
    {
        return $this->ownerUserId ?? throw new LogicException('This key has no resolvable owner.');
    }
}
