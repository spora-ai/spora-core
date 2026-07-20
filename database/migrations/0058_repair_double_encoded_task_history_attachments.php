<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * One-shot repair for `task_history.attachments` rows that were
 * written by the pre-#150 `Orchestrator::appendHistory()`, which
 * called `json_encode()` on the ref list before assigning it to a
 * column already declared `cast => 'array'` — so Eloquent
 * re-encoded the value and the DB ended up with a JSON-quoted
 * string instead of a JSON list.
 *
 * Detection: `json_decode($value)` returns a STRING (not array).
 * A correctly-encoded value decodes straight to a list of refs.
 *
 * Repair: `json_encode(json_decode($value, true), JSON_THROW_ON_ERROR)`
 * — decode the outer layer to recover the inner list, then re-encode
 * cleanly. The `array` cast on read returns the same shape as fresh
 * rows, so downstream consumers (`MessageHistoryBuilder::collectAttachmentBlocks`)
 * see a list again.
 *
 * Idempotent: leaves correctly-encoded rows untouched. Safe to
 * re-run.
 *
 * Out of scope: same anti-pattern in `ToolCall::proposed_arguments`
 * and `ToolCall::result_data` is fixed in code (ToolCallExecutor,
 * PR #150) but no equivalent bulk repair runs here — those columns
 * are not user-facing the same way `task_history.attachments` is
 * (the LLM never sees them as text), so any pre-#150 rows can stay
 * as double-encoded and only new rows are written correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('task_history') || !$schema->hasColumn('task_history', 'attachments')) {
            return;
        }

        // Only `role='attachment'` rows can carry this shape — the
        // bug was specific to the attachment-ref writer path. Filtering
        // by role keeps the row count scanned bounded and avoids
        // accidentally re-encoding any other `attachments` JSON the
        // schema might have picked up later.
        $rows = Capsule::table('task_history')
            ->where('role', 'attachment')
            ->whereNotNull('attachments')
            ->where('attachments', '!=', '')
            ->orderBy('id')
            ->cursor();

        $fixed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $decoded = json_decode($row->attachments, true);
            if (!is_string($decoded)) {
                continue;
            }

            // Outer decode returned a string — that's the inner JSON
            // of a double-encoded value. Re-decode to recover the
            // list, then re-encode once cleanly.
            $reDecoded = json_decode($decoded, true);
            if (!is_array($reDecoded)) {
                $skipped++;
                continue;
            }

            $rewritten = json_encode($reDecoded, JSON_THROW_ON_ERROR);

            try {
                Capsule::connection()->transaction(static function () use ($row, $rewritten): void {
                    Capsule::table('task_history')
                        ->where('id', $row->id)
                        ->update(['attachments' => $rewritten]);
                });
                $fixed++;
            } catch (\Throwable) {
                // Leave the row untouched on write failure. Operator
                // can re-run the migration after diagnosing the cause.
                $skipped++;
            }
        }

        // No `echo` from a migration: stdout is captured silently in
        // some harnesses. Operators who need a report should run
        // `bin/spora spora:install --verbose` (or the equivalent
        // Laravel migrate command with `-v`).
    }

    public function down(): void
    {
        // No-op: the rewrite is idempotent and lossless (the broken
        // shape is unreachable after `up()` succeeds). A second
        // `up()` re-encodes whatever shape is on disk to the
        // canonical list form, so `down()` does not need to
        // re-introduce the buggy shape.
    }
};
