<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;


return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('tasks', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 30)->default('PENDING');
            $table->text('user_prompt');
            $table->text('final_response')->nullable();
            $table->unsignedSmallInteger('step_count')->default(0);
            $table->unsignedTinyInteger('max_steps')->default(10);
            // MEDIUMTEXT: full conversation JSON can exceed MySQL TEXT 65KB limit
            $table->mediumText('pending_state')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('agent_id', 'idx_tasks_agent_id');
            $table->index('user_id', 'idx_tasks_user_id');
            $table->index('status', 'idx_tasks_status');
            $table->index('created_at', 'idx_tasks_created_at');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('tasks');
    }
};
