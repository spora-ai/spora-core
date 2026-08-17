<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Immutable bundle passed through every tool execution that needs ownership
 * context. The `runnerUserId` separates "who paid" from "who can see".
 *
 * Decoupling the two fields lets plugins read whichever interpretation they
 * need without re-deriving it from a shared accessor:
 *
 *   - `ownerUserId` — the user whose settings pay for this agent (the
 *     group's first `owner` if the principal is a group, the principal's
 *     `user_id` if it is a user-principal). Tools use this for credential
 *     encryption keys, audit attribution, and any API that requires "the
 *     paying user". Sharing semantics: owner's settings always apply.
 *
 *   - `runnerUserId` — the user who triggered the current task. Used by
 *     memory write attribution, Mercure publish targets, and the
 *     `tasks.user_id` column. Records who clicked, not who paid.
 *
 * Make both nullable so partially-resolved contexts (test stubs, controller
 * preview paths) keep type-safety.
 */
final readonly class PrincipalContext
{
    public function __construct(
        public int    $principalId,
        public string $type,
        public ?int   $ownerUserId,
        public ?int   $runnerUserId,
    ) {
    }

    /**
     * @return array{
     *     principal_id: int,
     *     type: string,
     *     owner_user_id: int|null,
     *     runner_user_id: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'principal_id'   => $this->principalId,
            'type'           => $this->type,
            'owner_user_id'  => $this->ownerUserId,
            'runner_user_id' => $this->runnerUserId,
        ];
    }
}
