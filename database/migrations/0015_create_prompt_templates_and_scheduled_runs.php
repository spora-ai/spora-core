<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('agent_prompt_templates', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('prompt_template');
            $table->json('variables')->nullable();
            $table->tinyInteger('max_steps')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->index(['agent_id', 'is_active']);
        });

        Capsule::schema()->create('scheduled_runs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('template_id')->nullable();
            $table->text('raw_prompt')->nullable();
            $table->string('cron_expression', 50)->nullable();
            $table->datetime('run_at')->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->tinyInteger('max_steps_override')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetime('last_run_at')->nullable();
            $table->datetime('next_run_at')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('agent_prompt_templates')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('scheduled_runs');
        Capsule::schema()->dropIfExists('agent_prompt_templates');
    }
};
