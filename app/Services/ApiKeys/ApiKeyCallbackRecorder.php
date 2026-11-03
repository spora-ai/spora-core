<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use Illuminate\Support\Carbon;
use Spora\Models\ApiCallback;
use Spora\Models\ApiKey;
use Spora\Models\Task;

/**
 * Records the callback a chat owes its receiver, at the moment the chat is
 * created.
 *
 * Written while the task is still `QUEUED`, which is what lets delivery
 * work without a task-lifecycle hook: the row exists before there is
 * anything to report, and the worker sweep only acts once the task has
 * settled. A row that outlives its chat is removed by the cascade on
 * `chat_id`.
 */
final class ApiKeyCallbackRecorder
{
    /**
     * Record the callback this chat owes, while it is still `QUEUED`. Returns
     * null — and writes nothing — when no `callback_url` was supplied.
     *
     * The agent id is the chat's agent: the target agent on the group and
     * global surfaces, the key's own agent on the agent one.
     */
    public function record(ApiKey $key, int $agentId, Task $task, ?string $callbackUrl): ?ApiCallback
    {
        if ($callbackUrl === null || $callbackUrl === '') {
            return null;
        }

        return ApiCallback::create([
            'chat_id' => (int) $task->id,
            'key_id' => $key->id,
            'agent_id' => $agentId,
            'url' => $callbackUrl,
            // Nothing is owed yet: the chat has not settled.
            'pending_status' => null,
            'attempts' => 0,
            // Armed immediately rather than left NULL. The sweep selects
            // `next_attempt_at IS NOT NULL AND <= now`, so a NULL here is a
            // row nothing can ever pick up — the chat would finish and no
            // callback would fire. `ApiKeyCallbackDispatcher` defers the
            // row again while the chat is still running.
            'next_attempt_at' => Carbon::now('UTC'),
        ]);
    }
}
