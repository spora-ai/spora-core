<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add `attachments` JSON column to `task_history`.
 *
 * Carries the per-message attachment references that
 * `MessageHistoryBuilder` expands into LLM content blocks. Each row's
 * `attachments` is a list of `{media_id, kind}` where `kind` is
 * `'text'` (extracted markdown flows in as a `text` block) or
 * `'image'` (the asset's bytes are inlined as a base64 `image` block
 * when the agent's LLM supports it; otherwise the row is filtered
 * out at message-build time).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('task_history')) {
            return;
        }
        if (!$schema->hasColumn('task_history', 'attachments')) {
            $schema->table('task_history', static function (Blueprint $table): void {
                $table->json('attachments')->nullable()->after('summarized_sequence_range');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('task_history')) {
            return;
        }
        if ($schema->hasColumn('task_history', 'attachments')) {
            $schema->table('task_history', static function (Blueprint $table): void {
                $table->dropColumn('attachments');
            });
        }
    }
};
