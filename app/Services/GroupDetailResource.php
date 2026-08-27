<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Group;
use Spora\Models\Principal;
use Spora\Services\ProfilePictures\GroupPictureService;

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
    /**
     * Tables whose row counts the resource exposes per-group. Each entry
     * is the SQL table name; the wire field name matches the table name.
     * Centralised so a new settings table only needs to be added in one
     * place.
     *
     * @var list<string>
     */
    private const COUNT_TABLES = [
        'agents',
        'llm_driver_configurations',
        'tool_user_settings',
    ];

    private function __construct()
    {
        // Static-only utility — instantiation is intentionally disallowed.
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(
        Group $group,
        int $callerUserId,
        PrincipalService $principalService,
        ?GroupPictureService $pictureService = null,
    ): array {
        $principal = $principalService->principalForGroup((int) $group->id);
        $principalCounts = self::principalCountsFor([$principal]);
        $memberCount = self::memberCountsFor([(int) $group->id])[(int) $group->id] ?? 0;

        return [
            'id'                  => (int) $group->id,
            'name'                => $group->name,
            'description'         => $group->description,
            'created_by_user_id'  => (int) $group->created_by_user_id,
            'principal_id'        => $principal !== null ? (int) $principal->id : null,
            'my_role'             => self::roleForCaller($group, $callerUserId),
            'member_count'        => $memberCount,
            'agent_count'         => self::extractCount($principalCounts, $principal, 'agents'),
            'llm_config_count'    => self::extractCount($principalCounts, $principal, 'llm_driver_configurations'),
            'tool_setting_count'  => self::extractCount($principalCounts, $principal, 'tool_user_settings'),
            'profile_picture'     => $pictureService?->toWireShape((int) $group->id),
            'created_at'          => $group->created_at->format(DateTimeInterface::ATOM),
            'updated_at'          => $group->updated_at->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param  iterable<Group>     $groups
     * @return list<array<string, mixed>>
     */
    public static function collect(
        iterable $groups,
        int $callerUserId,
        PrincipalService $principalService,
        ?GroupPictureService $pictureService = null,
    ): array {
        $list = is_array($groups) ? $groups : iterator_to_array($groups);
        if ($list === []) {
            return [];
        }

        // Resolve the group-principal for every group in one batch so
        // the per-group count fan-out below doesn't repeat the lookup.
        $principalsByGroup = [];
        foreach ($list as $g) {
            $principalsByGroup[(int) $g->id] = $principalService->principalForGroup((int) $g->id);
        }
        $principalCounts = self::principalCountsFor(array_values($principalsByGroup));
        $memberCounts = self::memberCountsFor(array_map(static fn(Group $g): int => (int) $g->id, $list));

        $out = [];
        foreach ($list as $g) {
            $groupId = (int) $g->id;
            $principal = $principalsByGroup[$groupId] ?? null;
            $out[] = [
                'id'                  => $groupId,
                'name'                => $g->name,
                'description'         => $g->description,
                'created_by_user_id'  => (int) $g->created_by_user_id,
                'principal_id'        => $principal !== null ? (int) $principal->id : null,
                'my_role'             => self::roleForCaller($g, $callerUserId),
                'member_count'        => $memberCounts[$groupId] ?? 0,
                'agent_count'         => self::extractCount($principalCounts, $principal, 'agents'),
                'llm_config_count'    => self::extractCount($principalCounts, $principal, 'llm_driver_configurations'),
                'tool_setting_count'  => self::extractCount($principalCounts, $principal, 'tool_user_settings'),
                'profile_picture'     => $pictureService?->toWireShape($groupId),
                'created_at'          => $g->created_at->format(DateTimeInterface::ATOM),
                'updated_at'          => $g->updated_at->format(DateTimeInterface::ATOM),
            ];
        }
        return $out;
    }

    /**
     * Issue one grouped count query per settings table and return
     * `principal_id => [table => count]`. Tables with no rows for any
     * of the supplied principals return an empty map (no entries, but
     * the field still exists when accessed via {@see self::extractCount}).
     *
     * @param  list<Principal|null> $principals
     * @return array<int, array<string, int>>
     */
    private static function principalCountsFor(array $principals): array
    {
        $principalIds = [];
        foreach ($principals as $p) {
            if ($p !== null) {
                $principalIds[(int) $p->id] = (int) $p->id;
            }
        }
        if ($principalIds === []) {
            return [];
        }

        $result = [];
        foreach (self::COUNT_TABLES as $table) {
            $rows = Capsule::table($table)
                ->selectRaw('principal_id, COUNT(*) AS aggregate_count')
                ->whereIn('principal_id', array_values($principalIds))
                ->groupBy('principal_id')
                ->get()
                ->all();
            foreach ($rows as $row) {
                /** @var object $row */
                $pid = (int) $row->principal_id;
                $result[$pid][$table] = (int) $row->aggregate_count;
            }
        }
        return $result;
    }

    /**
     * @param  array<int, array<string, int>> $principalCounts
     */
    private static function extractCount(array $principalCounts, ?Principal $principal, string $table): int
    {
        if ($principal === null) {
            return 0;
        }
        return $principalCounts[(int) $principal->id][$table] ?? 0;
    }

    /**
     * Issue one grouped count query and return `group_id => count`.
     *
     * @param  list<int> $groupIds
     * @return array<int, int>
     */
    private static function memberCountsFor(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $rows = Capsule::table('group_memberships')
            ->selectRaw('group_id, COUNT(*) AS aggregate_count')
            ->whereIn('group_id', array_values(array_unique($groupIds)))
            ->groupBy('group_id')
            ->get()
            ->all();
        $out = [];
        foreach ($rows as $row) {
            /** @var object $row */
            $out[(int) $row->group_id] = (int) $row->aggregate_count;
        }
        return $out;
    }

    private static function roleForCaller(Group $group, int $callerUserId): ?string
    {
        $role = \Spora\Models\GroupMembership::where('group_id', $group->id)
            ->where('user_id', $callerUserId)
            ->value('role');
        return $role !== null ? (string) $role : null;
    }
}
