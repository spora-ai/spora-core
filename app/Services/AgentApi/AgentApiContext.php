<?php

declare(strict_types=1);

namespace Spora\Services\AgentApi;

use LogicException;
use Spora\Http\Exceptions\AgentApiContextException;
use Spora\Models\Agent;
use Spora\Models\AgentApiKey;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * The authenticated caller behind one Agent API request.
 *
 * Read off the request rather than injected: `Router::invokeController()`
 * matches controller parameters against the route's path variables only,
 * so a value the middleware puts in `$request->attributes` is invisible to
 * a typed parameter. The Pest helper `callController()` *does* read
 * attributes, so injecting these would pass every test and fail in
 * production.
 */
final class AgentApiContext
{
    public function __construct(
        public readonly AgentApiKey $key,
        public readonly Agent $agent,
        public readonly int $ownerUserId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        /** @var self|null $context */
        $context = $request->attributes->get('agent_api_context');

        if (!$context instanceof self) {
            throw new LogicException('AgentApiContext::fromRequest called outside AgentApiKeyMiddleware.');
        }

        return $context;
    }

    /**
     * `ownerUserId` is the user id Spora attributes the chat to, written
     * to `tasks.trigger_user_id`. A group-owned agent resolves to the group
     * owner rather than to any member, so the attribution is stable
     * regardless of who happened to mint the key.
     */
    public static function build(AgentApiKey $key, Agent $agent, PrincipalResolver $resolver): self
    {
        $ownerUserId = $resolver->ownerUserId($agent->principal_id);

        if ($ownerUserId === null) {
            throw new AgentApiContextException('Agent has no resolvable owner principal.');
        }

        return new self($key, $agent, $ownerUserId);
    }

    public function agentId(): int
    {
        return $this->agent->id;
    }
}
