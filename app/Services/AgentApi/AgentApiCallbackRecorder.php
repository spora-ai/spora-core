<?php

declare(strict_types=1);

namespace Spora\Services\AgentApi;

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
    public function __construct(
        private readonly AgentApiChatProjection $projection,
    ) {}

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
        ]);
    }

    public function hasSettled(Task $task): bool
    {
        return $this->projection->hasSettled($task);
    }
}
