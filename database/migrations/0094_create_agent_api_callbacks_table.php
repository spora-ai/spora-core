<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Agent API callback outbox — one row per chat started with a
 * `callback_url`, holding the status the caller is still owed.
 *
 * Spora has no task-lifecycle event: the ~18 places that write a terminal
 * status are scattered with no chokepoint, and `BootingEvent` never fires
 * in `worker:run`. Rather than add one, the row is inserted when the chat
 * is created — while the task is still `QUEUED` — and the worker sweep
 * delivers it once the task settles. That keeps the delivery trigger on
 * the worker loop this table already has, and survives a crash mid-run.
 *
 * `pending_status` is the settle status owed to the caller;
 * `delivered_status` is the last one the receiver acknowledged. A chat
 * can settle twice (`PENDING_APPROVAL` → `QUEUED` → `COMPLETED`), and
 * those two columns are what distinguish "deliver again because the
 * status changed" from "retry because the last POST failed".
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('agent_api_callbacks')) {
            return;
        }

        $schema->create('agent_api_callbacks', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chat_id')->unique();
            $table->string('key_id', 32);
            $table->unsignedBigInteger('agent_id');
            $table->string('url', 2048);
            $table->string('pending_status', 30)->nullable();
            $table->string('delivered_status', 30)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            // The sweep's only query: owed rows whose backoff has elapsed.
            $table->index(['next_attempt_at', 'id']);
            $table->index('agent_id');
            $table->foreign('chat_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('key_id')->references('id')->on('agent_api_keys')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('agent_api_callbacks');
    }
};
