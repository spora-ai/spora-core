<?php

declare(strict_types=1);

namespace Spora\Services\AgentApi;

use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Spora\Models\AgentApiCallback;
use Spora\Models\Task;
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
 */
final class AgentApiCallbackDispatcher
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
        private readonly AgentApiChatProjection $projection,
        private readonly CallbackSecretResolver $secrets,
        private readonly LoggerInterface $logger,
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
            $rows = AgentApiCallback::whereNotNull('next_attempt_at')
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

    private function processRow(AgentApiCallback $row): bool
    {
        $task = Task::find($row->chat_id);

        if ($task === null) {
            // The chat is gone; the row cascades with it, but a sweep that
            // raced the delete should not re-derive it.
            return false;
        }

        if (!$this->projection->hasSettled($task)) {
            // Nothing to report yet, so push the row out of the due window
            // rather than leaving it due. Leaving it due looks harmless and
            // is not: the sweep is `ORDER BY id LIMIT 25`, so 25 chats still
            // running — all with lower ids — would occupy every batch and
            // starve every settled chat behind them, for as long as they ran.
            // Deferring is what lets a settled row reach the front.
            $row->next_attempt_at = Carbon::now('UTC')->addSeconds(self::SETTLE_POLL_SECONDS);
            $row->save();

            return false;
        }

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
     * Decide whether the sweep should keep watching a row after it has
     * reported `$status`.
     *
     * A terminal status cannot change, so the row is disarmed and never read
     * again. A blocked one can: the caller may approve, reject or answer, and
     * the chat then settles a second time and is owed a second delivery.
     * Disarming those drops that delivery silently, so they stay armed on a
     * slow cadence instead.
     */
    private function armOrDisarm(AgentApiCallback $row, string $status): void
    {
        $row->next_attempt_at = in_array($status, AgentApiChatProjection::BLOCKED_STATUSES, true)
            ? Carbon::now('UTC')->addSeconds(self::BLOCKED_POLL_SECONDS)
            : null;
    }

    private function deliver(AgentApiCallback $row, Task $task): bool
    {
        $secret = $this->secrets->for($row->key_id);

        if ($secret === null) {
            // The key was deleted; its callbacks go with it.
            $row->delete();

            return false;
        }

        $payload = json_encode($this->projection->status($task), JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = $this->sign($timestamp . '.' . $payload, $secret);

        $response = null;

        try {
            $response = $this->httpClient->request('POST', $row->url, [
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
            ]);

            $statusCode = $response->getStatusCode();
        } catch (Throwable $e) {
            $this->recordFailure($row, null, $e->getMessage());

            return false;
        }

        if ($statusCode >= 200 && $statusCode < 300) {
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

            return true;
        }

        // Symfony defers the transport error to a body read; a 4xx/5xx only
        // surfaces here, so drain the body before logging to release the
        // connection.
        $detail = $this->bodyError($response);

        $this->recordFailure($row, $statusCode, $detail);

        return false;
    }

    private function recordFailure(AgentApiCallback $row, ?int $statusCode, string $error): void
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

    private function bodyError(ResponseInterface $response): string
    {
        try {
            $body = $response->toArray(false);
        } catch (Throwable) {
            return 'HTTP error';
        }

        if (isset($body['error']['message']) && is_string($body['error']['message'])) {
            return $body['error']['message'];
        }

        return 'HTTP ' . $response->getStatusCode();
    }

    private function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

}
