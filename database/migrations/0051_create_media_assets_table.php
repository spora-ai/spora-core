<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // FKs are nullable so rows survive agent/task/tool_call deletion
            // (the UI renders "Orphaned agent" when the join is null).
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('tool_call_id')->nullable();

            $table->string('plugin_slug', 64)->nullable();
            $table->string('tool_name', 64)->nullable();

            // Coarse discriminator; index kept narrow on (media_type, created_at)
            // because most queries filter by type and order by recency.
            $table->string('media_type', 16)->nullable();

            $table->string('mime_type', 127)->nullable();
            $table->bigInteger('byte_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();

            $table->text('prompt')->nullable();

            // SQLite has no JSON type — text + cast handles it cross-engine.
            $table->text('tags')->nullable();
            $table->text('metadata')->nullable();

            // `asset_url` is the local /api/v1/assets/... URL when we promoted
            // the payload to disk; required (always set, even in external mode
            // when pointing at the CDN).
            $table->string('asset_url', 512);
            $table->string('source_url', 512)->nullable();

            // local: promoted via AssetStore; data_url: inline; external: CDN.
            $table->string('storage_mode', 16);

            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('tool_call_id')->references('id')->on('tool_calls')->nullOnDelete();

            $table->index(['media_type', 'created_at']);
            $table->index(['agent_id', 'created_at']);
            $table->index(['plugin_slug', 'tool_name', 'created_at']);

            // Idempotent re-ingest: the same tool call pointing at the same URL
            // upserts rather than duplicating rows.
            $table->unique(['tool_call_id', 'asset_url']);
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('media_assets');
    }
};