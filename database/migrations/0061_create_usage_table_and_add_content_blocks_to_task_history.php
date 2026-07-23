<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Adds prompt-cache + reasoning observability surfaces.
 *
 * Two pieces:
 *
 *  - New `usage` table — one row per assistant turn, foreign-keyed to
 *    `task_history.id`. Carries the seven typed token counters plus
 *    `provider`, `raw_usage` (the verbatim provider usage subobject), and
 *    `driver_meta_info` (forward-compat bag for non-normalized fields).
 *    Created as a separate table (not a column on `task_history`) so the
 *    legacy `task_history.reasoning` column can co-exist without forcing
 *    a destructive migration on existing data.
 *  - New nullable `content_blocks` JSON column on `task_history`. Carries
 *    the ordered list of `ContentBlock` VOs (text / image / thinking /
 *    redacted_thinking) emitted by the driver, including provider-signed
 *    `signature` and `data` fields that are stripped before serialisation
 *    to the frontend.
 *
 * No backfill: legacy rows without a usage row or content_blocks are
 * tolerated by `HistoryMessageContext::fromArray()` per the plan's
 * documented migration policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('usage')) {
            $schema->create('usage', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('task_history_id');
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('reasoning_tokens')->default(0);
                $table->unsignedInteger('cached_tokens')->default(0);
                $table->unsignedInteger('cache_creation_tokens')->default(0);
                $table->unsignedInteger('cache_read_tokens')->default(0);
                $table->string('provider', 32)->default('unknown');
                $table->json('raw_usage')->nullable();
                $table->json('driver_meta_info')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique('task_history_id', 'uq_usage_task_history_id');
                $table->foreign('task_history_id')
                    ->references('id')
                    ->on('task_history')
                    ->onDelete('cascade');
                $table->index('provider', 'idx_usage_provider');
            });
        }

        if ($schema->hasTable('task_history') && !$schema->hasColumn('task_history', 'content_blocks')) {
            $schema->table('task_history', static function (Blueprint $table): void {
                $table->json('content_blocks')->nullable()->after('attachments');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('task_history') && $schema->hasColumn('task_history', 'content_blocks')) {
            $schema->table('task_history', static function (Blueprint $table): void {
                $table->dropColumn('content_blocks');
            });
        }

        if ($schema->hasTable('usage')) {
            $schema->dropIfExists('usage');
        }
    }
};
