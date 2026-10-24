<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

use Illuminate\Support\Collection;
use Spora\Models\TaskHistory;

/**
 * The compactions in force for a task's history: the summary rows that stand
 * in for the rows they cover.
 *
 * A summary is *superseded* when a later summary covers everything it
 * covers. That is the normal case: a compaction re-summarises what the
 * previous one replaced plus the rows that followed it, so the newer range
 * is a superset and the older summary says the same thing about the same
 * rows. A superseded summary is not sent to the model — the newer one was
 * written from it.
 *
 * Two summaries whose ranges do not contain each other both stay in force.
 * That is not a shape a compaction produces, but it is a shape hand-written
 * history can have, and dropping either summary's rows without sending its
 * summary would drop context on the floor.
 *
 * The rows themselves stay in `task_history`. What this value object decides
 * is which of them the model is shown — see
 * {@see \Spora\Agents\MessageHistoryBuilder} for the outbound side and
 * {@see \Spora\Agents\ConversationCompactor} for the write side.
 */
final class ActiveCompactions
{
    /**
     * @param  list<array{row: TaskHistory, first: int, last: int}>  $summaries
     *         In-force summaries, ordered by the sequence their range starts at.
     */
    private function __construct(private readonly array $summaries) {}

    /**
     * @param  Collection<int, TaskHistory>  $rows  Every row of the task's history, in sequence order.
     */
    public static function find(Collection $rows): self
    {
        $candidates = [];

        foreach ($rows as $row) {
            if ($row->role !== 'summary' || $row->summarized_sequence_range === null) {
                continue;
            }

            $range = self::parseRange((string) $row->summarized_sequence_range);
            if ($range === null) {
                continue;
            }

            $candidates[] = ['row' => $row, 'first' => $range[0], 'last' => $range[1]];
        }

        $inForce = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => !self::isSuperseded($candidate, $candidates),
        ));

        usort(
            $inForce,
            static fn(array $a, array $b): int => [$a['first'], $a['row']->sequence] <=> [$b['first'], $b['row']->sequence],
        );

        return new self($inForce);
    }

    /**
     * @return array{0: int, 1: int}|null  The covered range, or null when the
     *                                    stored value is not a range.
     */
    public static function parseRange(string $range): ?array
    {
        if (preg_match('/^(\d+)-(\d+)$/', $range, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @param  array{row: TaskHistory, first: int, last: int}  $candidate
     * @param  list<array{row: TaskHistory, first: int, last: int}>  $candidates
     */
    private static function isSuperseded(array $candidate, array $candidates): bool
    {
        foreach ($candidates as $other) {
            if ($other['row']->sequence <= $candidate['row']->sequence) {
                continue;
            }
            if ($other['first'] > $candidate['first'] || $other['last'] < $candidate['last']) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<array{row: TaskHistory, first: int, last: int}>
     */
    public function summaries(): array
    {
        return $this->summaries;
    }

    /**
     * Whether the row at `$sequence` is one the summaries stand in for.
     */
    public function covers(int $sequence): bool
    {
        foreach ($this->summaries as $summary) {
            if ($sequence >= $summary['first'] && $sequence <= $summary['last']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lowest sequence any in-force summary covers.
     */
    public function firstSequence(): ?int
    {
        if ($this->summaries === []) {
            return null;
        }

        return $this->summaries[0]['first'];
    }

    /**
     * The highest sequence any in-force summary covers.
     */
    public function lastSequence(): int
    {
        $last = -1;
        foreach ($this->summaries as $summary) {
            $last = max($last, $summary['last']);
        }

        return $last;
    }
}
