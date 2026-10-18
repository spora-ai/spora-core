<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Agent API keys — one credential per agent, minted from the agent's
 * API keys tab and presented as `spk_<id>_<secret>`.
 *
 * `id` is the 16-byte random hex that appears in the key string and is
 * used to find the row. It is a random PK rather than an autoincrement
 * so a key id cannot be walked, and so `/api/v1/agent-api/media/{id}`
 * style routes have no enumerable half to probe with.
 *
 * `secret_hash` is a SHA-256 hex digest, never the secret. The secret
 * is shown exactly once at creation; there is no endpoint that can
 * return it afterwards, so the row cannot be used to recover it either.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('agent_api_keys')) {
            return;
        }

        $schema->create('agent_api_keys', static function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->unsignedBigInteger('agent_id');
            $table->string('name', 100);
            $table->string('secret_hash', 64);
            $table->dateTime('last_used_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('agent_id');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('agent_api_keys');
    }
};
