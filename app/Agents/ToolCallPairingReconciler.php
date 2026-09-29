<?php

declare(strict_types=1);

namespace Spora\Agents;

/**
 * Repairs provider tool-call pairing (HTTP 400 error 2013) on the read path: a
 * `tool` message is legal only directly after the assistant message declaring
 * its id, before any other role intervenes. Each declared call is either
 * matched to its result or dropped and restated as user text.
 */
final class ToolCallPairingReconciler
{
    /** A multi-KB argument blob would cost more context than the marker is worth. */
    private const MAX_ARGUMENT_PREVIEW_CHARS = 200;

    /** Providers require `content` on an assistant message carrying no `tool_calls`. */
    private const EMPTY_TURN_PLACEHOLDER = '[interrupted]';

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function reconcile(array $messages): array
    {
        $out      = [];
        $deferred = [];

        /** @var array{index: int, calls: array<string, array<string, mixed>>, surplus: list<array<string, mixed>>}|null $pending */
        $pending = null;

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'tool') {
                $id = (string) ($msg['tool_call_id'] ?? '');

                if ($pending !== null && array_key_exists($id, $pending['calls'])) {
                    unset($pending['calls'][$id]);
                    $out[] = $msg;
                    continue;
                }

                // Out of order, a duplicate, or its declaration was evicted.
                $deferred[] = $this->orphanedResultMessage($msg);
                continue;
            }

            // The run must stay contiguous, so any non-tool message closes it.
            if ($pending !== null) {
                $this->closeBatch($pending, $out);
                $pending = null;
            }
            foreach ($deferred as $orphan) {
                $out[] = $orphan;
            }
            $deferred = [];

            $declaration = $this->declaredCalls($msg);
            $index       = count($out);
            $out[]       = $msg;

            if ($declaration['unique'] === []) {
                continue;
            }

            if ($declaration['surplus'] !== []) {
                $out[$index]['tool_calls'] = $declaration['unique'];
            }

            $pending = [
                'index'   => $index,
                'calls'   => $this->callsById($declaration['unique']),
                'surplus' => $declaration['surplus'],
            ];
        }

        if ($pending !== null) {
            $this->closeBatch($pending, $out);
        }
        foreach ($deferred as $orphan) {
            $out[] = $orphan;
        }

        return $out;
    }

    /**
     * One marker row per repaired batch keeps the repair from restating each
     * dangling call separately; it does not guarantee alternating roles, since
     * an interrupting `user` or compaction row is itself `user`. Providers
     * combine consecutive same-role turns, so the run stays valid.
     *
     * @param  array{index: int, calls: array<string, array<string, mixed>>, surplus: list<array<string, mixed>>}  $pending
     * @param  list<array<string, mixed>>  $out
     */
    private function closeBatch(array $pending, array &$out): void
    {
        if ($pending['calls'] === [] && $pending['surplus'] === []) {
            return;
        }

        $assistant = &$out[$pending['index']];
        $answered  = [];

        foreach ((array) ($assistant['tool_calls'] ?? []) as $call) {
            if (is_array($call) && ! array_key_exists((string) ($call['id'] ?? ''), $pending['calls'])) {
                $answered[] = $call;
            }
        }

        if ($answered === []) {
            unset($assistant['tool_calls']);
            $content = $assistant['content'] ?? null;
            // A `content_blocks` array is real prose the provider must replay;
            // only a genuinely absent or blank `content` needs the placeholder.
            if ($content === null || (is_string($content) && trim($content) === '')) {
                $assistant['content'] = self::EMPTY_TURN_PLACEHOLDER;
            }
        } else {
            $assistant['tool_calls'] = $answered;
        }
        unset($assistant);

        $markers = [];
        foreach (array_merge($pending['calls'], $pending['surplus']) as $call) {
            $markers[] = $this->abandonedCallMessage($call);
        }

        $out[] = [
            'role'    => 'user',
            'content' => implode("\n", $markers),
        ];
    }

    /**
     * Only one `tool` row can ever consume a given id, so a second declaration
     * of the same `tool_call_id` is unanswerable. It is split off into
     * `surplus`: the wire payload keeps the first occurrence, and the surplus is
     * repaired as an abandoned call rather than shipped as a 400.
     *
     * @param  array<string, mixed>  $msg
     * @return array{unique: list<array<string, mixed>>, surplus: list<array<string, mixed>>}
     */
    private function declaredCalls(array $msg): array
    {
        if (($msg['role'] ?? '') !== 'assistant') {
            return ['unique' => [], 'surplus' => []];
        }

        $calls = $msg['tool_calls'] ?? null;
        if (! is_array($calls)) {
            return ['unique' => [], 'surplus' => []];
        }

        $unique  = [];
        $surplus = [];
        $seen    = [];
        foreach ($calls as $call) {
            if (! is_array($call)) {
                continue;
            }

            $id = (string) ($call['id'] ?? '');
            if (array_key_exists($id, $seen)) {
                $surplus[] = $call;
                continue;
            }

            $seen[$id] = true;
            $unique[]  = $call;
        }

        return ['unique' => $unique, 'surplus' => $surplus];
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, array<string, mixed>>
     */
    private function callsById(array $calls): array
    {
        $byId = [];
        foreach ($calls as $call) {
            $id = (string) ($call['id'] ?? '');
            if (array_key_exists($id, $byId)) {
                continue;
            }
            $byId[$id] = $call;
        }

        return $byId;
    }

    private function abandonedCallMessage(array $call): string
    {
        $function = is_array($call['function'] ?? null) ? $call['function'] : [];
        $label    = sprintf('[tool:%s] tool call did not return a result.', (string) ($function['name'] ?? 'unknown'));

        $preview = $this->argumentPreview($function['arguments'] ?? null);

        return $preview === '' ? $label : $label . ' arguments: ' . $preview;
    }

    /**
     * @param  array<string, mixed>  $msg
     * @return array<string, mixed>
     */
    private function orphanedResultMessage(array $msg): array
    {
        $label = sprintf(
            '[tool:%s] result recorded for a call that does not immediately precede it (id=%s).',
            (string) ($msg['name'] ?? 'unknown'),
            (string) ($msg['tool_call_id'] ?? ''),
        );

        $content = $msg['content'] ?? null;

        return [
            'role'    => 'user',
            'content' => is_string($content) && $content !== '' ? $label . ' ' . $content : $label,
        ];
    }

    private function argumentPreview(mixed $arguments): string
    {
        $arguments = is_array($arguments) ? json_encode($arguments, JSON_UNESCAPED_SLASHES) : $arguments;
        $text      = is_string($arguments) ? trim($arguments) : '';

        if ($text === '' || $text === '{}' || $text === '[]') {
            return '';
        }

        $suffix = '… [truncated]';

        // A stored `arguments` string holds real UTF-8 once decoded, so the cut
        // has to land on a character boundary: a byte-wise `substr` can split a
        // code point and Symfony's JSON_THROW_ON_ERROR then kills the tick with
        // an uncaught JsonException before the request is even sent.
        return strlen($text) <= self::MAX_ARGUMENT_PREVIEW_CHARS
            ? $text
            : mb_strcut($text, 0, self::MAX_ARGUMENT_PREVIEW_CHARS - strlen($suffix), 'UTF-8') . $suffix;
    }
}
