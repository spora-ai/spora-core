<?php

declare(strict_types=1);

namespace Spora\Services\AgentApi;

use Illuminate\Support\Carbon;
use Spora\Models\AgentApiCallback;
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
final class AgentApiCallbackRecorder
{
    /**
     * Record the callback this chat owes, while it is still `QUEUED`. Returns
     * null — and writes nothing — when no `callback_url` was supplied.
     */
    public function record(AgentApiContext $context, Task $task, ?string $callbackUrl): ?AgentApiCallback
    {
        if ($callbackUrl === null || $callbackUrl === '') {
            return null;
        }

        return AgentApiCallback::create([
            'chat_id' => (int) $task->id,
            'key_id' => $context->key->id,
            'agent_id' => $context->agentId(),
            'url' => $callbackUrl,
            // Nothing is owed yet: the chat has not settled.
            'pending_status' => null,
            'attempts' => 0,
            // Armed immediately rather than left NULL. The sweep selects
            // `next_attempt_at IS NOT NULL AND <= now`, so a NULL here is a
            // row nothing can ever pick up — the chat would finish and no
            // callback would fire. `AgentApiCallbackDispatcher` defers the
            // row again while the chat is still running.
            'next_attempt_at' => Carbon::now('UTC'),
        ]);
    }
}
