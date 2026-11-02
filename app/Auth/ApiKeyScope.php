<?php

declare(strict_types=1);

namespace Spora\Auth;

use InvalidArgumentException;

/**
 * What a credential is allowed to reach.
 *
 * The scope is a fact about the row, not something re-derived from which
 * foreign key happens to be set — a key that names neither an agent nor a
 * group is a global key, and saying so in one column keeps the rule out of
 * every reader that would otherwise have to guess.
 *
 * The target a scope names is not optional and not free-form:
 *
 *   - `Agent`  names one agent,          `agent_id` set, `group_id` null
 *   - `Group`  names one group,          `group_id` set, `agent_id` null
 *   - `Global` names the whole install,  both null
 *
 * {@see self::assertConsistent()} is the only place that rule is written
 * down. It is checked when a key is minted and again when a key
 * authenticates, because a row edited by hand is not a row the code wrote.
 */
enum ApiKeyScope: string
{
    case Agent = 'agent';

    case Group = 'group';

    case Global = 'global';

    /**
     * @throws InvalidArgumentException when the target does not match the scope
     */
    public function assertConsistent(?int $agentId, ?int $groupId): void
    {
        $consistent = match ($this) {
            self::Agent => $agentId !== null && $groupId === null,
            self::Group => $groupId !== null && $agentId === null,
            self::Global => $agentId === null && $groupId === null,
        };

        if (!$consistent) {
            throw new InvalidArgumentException(sprintf(
                "A '%s' key must name %s.",
                $this->value,
                match ($this) {
                    self::Agent => 'exactly one agent and no group',
                    self::Group => 'exactly one group and no agent',
                    self::Global => 'neither an agent nor a group',
                },
            ));
        }
    }

    /**
     * The scope a row claims, or null when the column holds something this
     * build does not know — an unrecognised scope is an invalid credential,
     * not a scope with no reach.
     */
    public static function fromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
