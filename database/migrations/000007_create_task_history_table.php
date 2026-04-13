<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;


return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('task_history', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('role', 20);
            $table->text('content')->nullable();
            $table->text('reasoning')->nullable();
            $table->string('tool_call_id', 100)->nullable();
            $table->string('tool_name', 100)->nullable();
            $table->text('tool_call_payload')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('task_id', 'idx_task_history_task_id');
            $table->unique(['task_id', 'sequence'], 'uq_task_history_sequence');
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('task_history');
    }
};
