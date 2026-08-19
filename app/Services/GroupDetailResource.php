<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTimeInterface;
use Spora\Models\Group;
use Spora\Models\Principal;

/**
 * Group → wire-format array mapping, plus the index-page serialiser that
 * fans the same shape out over many groups. Lives outside the controller
 * so the mappings stay byte-identical between GET /api/v1/groups (admin
 * and member branches) and GET /api/v1/groups/{id}.
 *
 * Mirrors {@see AgentResource} for agents — single source of truth for
 * the wire shape, decoupled from any service.
 */
final class GroupDetailResource
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Group $group, int $callerUserId, PrincipalService $principalService): array
    {
        $principal = $principalService->principalForGroup((int) $group->id);

        return [
            'id'                  => (int) $group->id,
            'name'                => $group->name,
            'description'         => $group->description,
            'created_by_user_id'  => (int) $group->created_by_user_id,
            'principal_id'        => $principal !== null ? (int) $principal->id : null,
            'caller_role'         => self::roleForCaller($group, $callerUserId),
            'member_count'        => self::memberCountForGroup($group),
            'agent_count'         => self::countForPrincipal($principal, 'agents', 'principal_id'),
            'llm_config_count'    => self::countForPrincipal($principal, 'llm_driver_configurations', 'principal_id'),
            'tool_setting_count'  => self::countForPrincipal($principal, 'tool_user_settings', 'principal_id'),
            'created_at'          => $group->created_at->format(DateTimeInterface::ATOM),
            'updated_at'          => $group->updated_at->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param  iterable<Group>     $groups
     * @return list<array<string, mixed>>
     */
    public static function collect(iterable $groups, int $callerUserId, PrincipalService $principalService): array
    {
        $out = [];
        foreach ($groups as $g) {
            $out[] = self::toArray($g, $callerUserId, $principalService);
        }
        return $out;
    }

    /**
     * Count rows for the group-principal on one of the settings tables
     * (or `agents`). Returns 0 when the principal has not been
     * materialised yet so a freshly-created group with no principal row
     * doesn't 500.
     */
    private static function countForPrincipal(?Principal $principal, string $table, string $column): int
    {
        if ($principal === null) {
            return 0;
        }
        return (int) \Illuminate\Database\Capsule\Manager::table($table)
            ->where($column, (int) $principal->id)
            ->count();
    }

    private static function roleForCaller(Group $group, int $callerUserId): ?string
    {
        $role = \Spora\Models\GroupMembership::where('group_id', $group->id)
            ->where('user_id', $callerUserId)
            ->value('role');
        return $role !== null ? (string) $role : null;
    }

    private static function memberCountForGroup(Group $group): int
    {
        return (int) \Spora\Models\GroupMembership::where('group_id', $group->id)->count();
    }
}
