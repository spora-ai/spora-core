<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\Task;
use Spora\Services\MediaArchive\MediaArchiveService;

/**
 * What a key may read of the media archive.
 *
 * Split out of {@see ApiKeyChatAccess} because the media rules are their
 * own thing: an asset is readable through the agent that produced it, through
 * the chat tree it belongs to, or through a chat that attached it — and the
 * chat tree is walked per asset rather than per chat.
 */
final class ApiKeyMediaAccess
{
    /** Sub-agent nesting is shallow; this is a cycle guard, not a real limit. */
    private const MAX_ANCESTOR_WALK = 16;

    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly ApiKeyReach $reach,
    ) {}

    /**
     * Whether the key may read the asset.
     *
     * The reach is the key's whole: a group key can read any of its
     * group's media, which is what the group is.
     */
    public function isMediaReadable(ApiKeyContext $ctx, string $mediaId): bool
    {
        return $this->isMediaReadableIn($this->reach->agentIds($ctx), $mediaId);
    }

    /**
     * Whether the key may read the asset through one specific agent.
     *
     * The start path needs this rather than the reach-wide check: a chat is
     * started with *one* agent, and an attachment that belongs to a sibling
     * agent in the same group has no business in it.
     */
    public function isMediaReadableForAgent(int $agentId, string $mediaId): bool
    {
        return $this->isMediaReadableIn(
            Agent::query()->select('id')->where('id', $agentId),
            $mediaId,
        );
    }

    /**
     * The asset, when the key may read it.
     */
    public function findMedia(ApiKeyContext $ctx, string $mediaId): ?MediaAsset
    {
        $asset = $this->mediaArchive->find($mediaId);

        if ($asset === null || !$this->isAssetReadable($this->reach->agentIds($ctx), $asset)) {
            return null;
        }

        return $asset;
    }

    /**
     * @param Builder<Agent> $agentIds
     */
    private function isMediaReadableIn(Builder $agentIds, string $mediaId): bool
    {
        $asset = $this->mediaArchive->find($mediaId);

        if ($asset === null) {
            return false;
        }

        return $this->isAssetReadable($agentIds, $asset);
    }

    /**
     * @param Builder<Agent> $agentIds
     */
    private function isAssetReadable(Builder $agentIds, MediaAsset $asset): bool
    {
        if ($asset->agent_id !== null && $this->ownsAgent($agentIds, (int) $asset->agent_id)) {
            return true;
        }

        if ($asset->task_id !== null && $this->isTaskInScope($agentIds, (int) $asset->task_id)) {
            return true;
        }

        return $this->isAttachedToScopedChat($agentIds, (string) $asset->id);
    }

    /**
     * @param Builder<Agent> $agentIds
     */
    private function ownsAgent(Builder $agentIds, int $agentId): bool
    {
        return Agent::query()->where('id', $agentId)->whereIn('id', $agentIds)->exists();
    }

    /**
     * Whether a task belongs to a reachable agent, or descends from one of
     * its tasks.
     *
     * Walks *up* the `parent_task_id` chain rather than down: the asset on a
     * sub-agent's chat carries the sub-task's id, and it is the parent's chat
     * that a caller of the parent can see. A depth cap stops a cycle in the
     * chain from hanging the request.
     *
     * @param Builder<Agent> $agentIds
     */
    private function isTaskInScope(Builder $agentIds, int $taskId): bool
    {
        $seen = [];
        $current = $taskId;

        for ($depth = 0; $depth < self::MAX_ANCESTOR_WALK; $depth++) {
            $task = Task::find($current);

            if ($task === null || isset($seen[$current])) {
                return false;
            }

            $seen[$current] = true;

            if ($this->ownsAgent($agentIds, (int) $task->agent_id)) {
                return true;
            }

            // 0 is the end-of-chain sentinel: ids are auto-increment from 1,
            // so the next `find()` misses and the climb stops.
            $current = $task->parent_task_id === null ? 0 : (int) $task->parent_task_id;
        }

        return false;
    }

    /**
     * An upload ingested for a chat carries no `agent_id` and no `task_id`
     * on the media row — the linkage is the `task_history` attachment row —
     * so that is the only place an inbound attachment can be traced back.
     *
     * @param Builder<Agent> $agentIds
     */
    private function isAttachedToScopedChat(Builder $agentIds, string $mediaId): bool
    {
        return DB::table('task_history')
            ->join('tasks', 'tasks.id', '=', 'task_history.task_id')
            ->whereIn('tasks.agent_id', $agentIds)
            ->where('task_history.attachments', 'like', '%"' . $mediaId . '"%')
            ->exists();
    }
}
