<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('scheduled_runs_next', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheduled_run_id');
            $table->dateTime('due_at');
            $table->enum('status', ['PENDING', 'CLAIMED', 'DONE', 'SKIPPED'])->default('PENDING');
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamps();

            $table->foreign('scheduled_run_id')
                ->references('id')
                ->on('scheduled_runs')
                ->onDelete('cascade');

            $table->foreign('task_id')
                ->references('id')
                ->on('tasks')
                ->onDelete('set null');

            $table->unique(['scheduled_run_id', 'due_at'], 'scheduled_runs_next_unique');
            $table->index(['status', 'due_at'], 'scheduled_runs_next_poll');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('scheduled_runs_next');
    }
};