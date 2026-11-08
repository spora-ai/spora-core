<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Spora\Auth\ApiKeyScope;
use Spora\Models\Agent;
use Spora\Models\ApiCallback;
use Spora\Models\ApiKey;
use Spora\Models\Task;
use Spora\Services\PrincipalResolver;
use Spora\Services\SsrfGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Delivers Agent API callbacks from the worker loop.
 *
 * Spora has no task-lifecycle event — terminal statuses are written across
 * the orchestrator and the worker with no chokepoint, and `BootingEvent`
 * never fires in `worker:run` — so delivery is driven from here instead:
 * the sweep looks for rows that still owe a status and posts it. Because
 * the row was created when the chat was, a crash between settle and
 * delivery loses nothing; the next sweep finds the row still owed.
 *
 * A chat can settle more than once (`PENDING_APPROVAL` → `QUEUED` →
 * `COMPLETED`), so delivery is keyed on the *status* rather than fired on
 * the terminal transition: `pending_status` is what is owed,
 * `delivered_status` is what the receiver last acknowledged, and the two
 * only diverge while a POST is outstanding.
 *
 * A registration is a snapshot, and both halves of it can change: the key
 * can be revoked or lose its reach over the agent, and the name it points at
 * can start answering with another address. So every delivery re-checks the
 * key's reach and screens the destination again.
 */
final class ApiKeyCallbackDispatcher
{
    public const MAX_ATTEMPTS = 6;

    private const BASE_BACKOFF_SECONDS = 30;

    private const MAX_BACKOFF_SECONDS = 3600;

    private const BATCH_LIMIT = 25;

    /**
     * How long a callback waits before the sweep re-checks whether its chat
     * has settled. This is the delivery latency floor: a chat that settles
     * between two checks waits out the remainder.
     */
    private const SETTLE_POLL_SECONDS = 5;

    /**
     * How often a chat already reported as blocked is re-checked for a second
     * settlement. Slow, because a blocked chat is waiting on a person and the
     * alternative — watching it every sweep — makes a chat nobody clears
     * cost a query per iteration indefinitely.
     */
    private const BLOCKED_POLL_SECONDS = 30;

    private const TIMEOUT_SECONDS = 5.0;

    private const MAX_DURATION_SECONDS = 10.0;

    private const SIGNATURE_HEADER = 'X-Spora-Signature';

    private const TIMESTAMP_HEADER = 'X-Spora-Timestamp';

    private const KEY_HEADER = 'X-Spora-Key-Id';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ApiKeyChatProjection $projection,
        private readonly CallbackSecretResolver $secrets,
        private readonly LoggerInterface $logger,
        // The guard screens the stored URL again before every delivery: the
        // name may answer with a different address than it did when the
        // callback was registered.
        private readonly SsrfGuard $guard = new SsrfGuard(),
        private readonly ApiKeyReach $reach = new ApiKeyReach(new PrincipalResolver()),
    ) {}

    /**
     * Deliver every callback that is owed and due. Returns how many
     * receivers were successfully POSTed.
     *
     * Never throws: this runs inside the worker loop, and a receiver that
     * is down must not stall task execution.
     */
    public function dispatchDue(): int
    {
        $delivered = 0;

        try {
            $rows = ApiCallback::whereNotNull('next_attempt_at')
                ->where('next_attempt_at', '<=', gmdate('Y-m-d H:i:s'))
                ->orderBy('id')
                ->limit(self::BATCH_LIMIT)
                ->get();
        } catch (Throwable $e) {
            $this->logger->error('Agent API callback sweep failed to load rows', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        foreach ($rows as $row) {
            try {
                if ($this->processRow($row)) {
                    $delivered++;
                }
            } catch (Throwable $e) {
                $this->logger->error('Agent API callback delivery failed', [
                    'callback_id' => $row->id,
                    'chat_id' => $row->chat_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $delivered;
    }

    private function processRow(ApiCallback $row): bool
    {
        $task = Task::find($row->chat_id);

        if ($task === null) {
            // The chat is gone; the row cascades with it, but a sweep that
            // raced the delete should not re-derive it.
            return false;
        }

        if (!$this->projection->hasSettled($task)) {
            $this->defer($row);

            return false;
        }

        return $this->deliverSettled($row, $task);
    }

    private function deliverSettled(ApiCallback $row, Task $task): bool
    {
        $status = (string) $task->status;

        if ($row->pending_status !== $status) {
            // The chat settled somewhere new — deliver again, and reset the
            // backoff because this is a different delivery, not a retry.
            $row->pending_status = $status;
            $row->attempts = 0;
        }

        if ($row->delivered_status === $row->pending_status) {
            $this->armOrDisarm($row, $status);
            $row->save();

            return false;
        }

        return $this->deliver($row, $task);
    }

    /**
     * Push a row that has nothing to report yet out of the due window.
     *
     * Leaving it due looks harmless and is not: the sweep is
     * `ORDER BY id LIMIT 25`, so 25 chats still running — all with lower
     * ids — would occupy every batch and starve every settled chat behind
     * them, for as long as they ran. Deferring is what lets a settled row
     * reach the front.
     */
    private function defer(ApiCallback $row): void
    {
        $row->next_attempt_at = Carbon::now('UTC')->addSeconds(self::SETTLE_POLL_SECONDS);
        $row->save();
    }

    /**
     * Decide whether the sweep should keep watching a row after it has
     * reported `$status`.
     *
     * A terminal status cannot change, so the row is disarmed and never read
     * again. A blocked one can: the caller may approve, reject or answer, and
     * the chat then settles a second time and is owed a second delivery.
     * Disarming those drops that delivery silently, so they stay armed on a
     * slow cadence instead.
     */
    private function armOrDisarm(ApiCallback $row, string $status): void
    {
        $row->next_attempt_at = in_array($status, ApiKeyChatProjection::BLOCKED_STATUSES, true)
            ? Carbon::now('UTC')->addSeconds(self::BLOCKED_POLL_SECONDS)
            : null;
    }

    private function deliver(ApiCallback $row, Task $task): bool
    {
        if (!$this->keyReachesTask($row, $task)) {
            // The key that registered this callback no longer reaches the
            // task's agent — it was revoked, or the agent moved to another
            // principal. The subscription goes with the access: the receiver
            // is no longer entitled to this task's data.
            $row->delete();

            return false;
        }

        $response = $this->post($row, $task);

        if (!$response instanceof ResponseInterface) {
            // `post()` records the reason; there is nothing left to decide.
            return false;
        }

        try {
            $statusCode = $response->getStatusCode();
        } catch (Throwable $e) {
            // The transport failed while the response was being read. The
            // attempt has to be accounted for like any other failure, or the
            // row stays due and is retried for ever.
            $this->recordFailure($row, null, $e->getMessage());

            return false;
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            // The receiver's body is not read: it is the receiver's to shape,
            // and a small gzipped answer can expand into a large allocation
            // in the worker before the message is truncated.
            $response->cancel();
            $this->recordFailure($row, $statusCode, 'HTTP ' . $statusCode);

            return false;
        }

        $this->recordSuccess($row, $task, $statusCode);

        return true;
    }

    /**
     * Whether the key that registered the callback may still touch the task's
     * agent.
     *
     * The registration is a snapshot: the key can have been revoked, or the
     * agent can have moved to another principal, since it was made.
     */
    private function keyReachesTask(ApiCallback $row, Task $task): bool
    {
        $key = ApiKey::find($row->key_id);

        if ($key === null) {
            return false;
        }

        $scope = ApiKeyScope::fromValue($key->scope);

        if ($scope === null) {
            return false;
        }

        $agent = $scope === ApiKeyScope::Agent && $key->agent_id !== null
            ? Agent::find($key->agent_id)
            : null;
        $groupId = $key->group_id === null ? null : (int) $key->group_id;

        return $this->reach->allows(new ApiKeyContext($key, $scope, $agent, null, $groupId), (int) $task->agent_id);
    }

    /**
     * Issue the POST, or null when it never landed — in which case the row
     * has already been deleted or its failure recorded, because either way
     * there is no status code for {@see self::deliver()} to judge.
     */
    private function post(ApiCallback $row, Task $task): ?ResponseInterface
    {
        $secret = $this->secrets->for($row->key_id);

        if ($secret === null) {
            // The key was deleted; its callbacks go with it.
            $row->delete();

            return null;
        }

        // The name the callback was registered under is resolved again here:
        // the answers it gave then are not the answers it gives now, and the
        // request goes to the address this screening vetted.
        $screen = $this->guard->screen($row->url);

        if ($screen['reason'] !== null) {
            $this->recordFailure($row, null, $screen['reason']);

            return null;
        }

        $payload = json_encode($this->projection->status($task), JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = $this->sign($timestamp . '.' . $payload, $secret);

        try {
            return $this->httpClient->request('POST', $row->url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    self::KEY_HEADER => $row->key_id,
                    self::TIMESTAMP_HEADER => $timestamp,
                    self::SIGNATURE_HEADER => $signature,
                ],
                'body' => $payload,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                // `SsrfGuard` screened the URL as supplied, once. Following a
                // redirect would re-issue the POST to a host that was never
                // screened — a `302` to the cloud metadata endpoint turns the
                // guard into a comment. A 3xx falls through to
                // `recordFailure()` and is retried, which is the right answer
                // for a receiver that has no reason to redirect.
                'max_redirects' => 0,
                'resolve' => $screen['resolve'],
            ]);
        } catch (Throwable $e) {
            $this->recordFailure($row, null, $e->getMessage());

            return null;
        }
    }

    private function recordSuccess(ApiCallback $row, Task $task, int $statusCode): void
    {
        $row->delivered_status = $row->pending_status;
        $this->armOrDisarm($row, (string) $task->status);
        $row->attempts = (int) $row->attempts + 1;
        $row->last_status_code = $statusCode;
        $row->last_error = null;
        $row->delivered_at = Carbon::now('UTC');
        $row->save();

        $this->logger->info('Agent API callback delivered', [
            'callback_id' => $row->id,
            'chat_id' => $row->chat_id,
            'status' => $row->pending_status,
            'http_status' => $statusCode,
        ]);
    }

    private function recordFailure(ApiCallback $row, ?int $statusCode, string $error): void
    {
        $attempts = (int) $row->attempts + 1;
        $row->attempts = $attempts;
        $row->last_status_code = $statusCode;
        $row->last_error = substr($error, 0, 500);

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Dead-letter: clear what is owed so the sweep stops picking the
            // row up, and leave the attempt count and last error visible.
            $row->next_attempt_at = null;
            $row->pending_status = null;

            $this->logger->warning('Agent API callback abandoned after max attempts', [
                'callback_id' => $row->id,
                'chat_id' => $row->chat_id,
                'attempts' => $attempts,
                'http_status' => $statusCode,
            ]);
        } else {
            $row->next_attempt_at = Carbon::now('UTC')->addSeconds(
                min(self::BASE_BACKOFF_SECONDS * (2 ** ($attempts - 1)), self::MAX_BACKOFF_SECONDS),
            );
        }

        $row->save();
    }

    private function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

}
