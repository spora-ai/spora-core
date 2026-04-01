<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;


return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('tool_calls', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('provider_call_id', 100);
            $table->string('tool_name', 100);
            $table->string('tool_class', 200);
            $table->string('tool_type', 10);
            $table->string('status', 20)->default('PENDING');
            $table->text('proposed_arguments');
            $table->text('human_description')->nullable();
            $table->text('approved_arguments')->nullable();
            $table->text('result_content')->nullable();
            $table->text('result_data')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approval_note', 500)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('task_id', 'idx_tool_calls_task_id');
            $table->index('agent_id', 'idx_tool_calls_agent_id');
            $table->index('status', 'idx_tool_calls_status');
            $table->index('tool_name', 'idx_tool_calls_tool_name');
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('tool_calls');
    }
};
