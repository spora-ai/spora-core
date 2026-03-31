<?php

declare(strict_types=1);

namespace Spora\Core;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

final class DatabaseSchemaInstaller
{
    public const CODE_VERSION = 1;

    public function install(): void
    {
        $this->runMigrations();
    }
    private function runMigrations(): void
    {
        $schema = Capsule::schema();

        // 1. users — delight-im/auth columns + Eloquent timestamps
        if (!$schema->hasTable('users')) {
            $schema->create('users', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('email', 249)->unique();
                $table->string('password', 255);
                $table->string('username', 100)->nullable();
                $table->tinyInteger('status')->default(0);
                $table->tinyInteger('verified')->default(0);
                $table->tinyInteger('resettable')->default(1);
                $table->unsignedInteger('roles_mask')->default(0);
                $table->unsignedInteger('registered');
                $table->unsignedInteger('last_login')->nullable();
                $table->unsignedMediumInteger('force_logout')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // 1b. delight-im/auth auxiliary tables
        if (!$schema->hasTable('users_2fa')) {
            $schema->create('users_2fa', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('mechanism');
                $table->string('seed', 255)->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('expires_at')->nullable();
                $table->unique(['user_id', 'mechanism'], 'users_2fa_user_id_mechanism_uq');
            });
        }

        if (!$schema->hasTable('users_audit_log')) {
            $schema->create('users_audit_log', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedInteger('event_at');
                $table->string('event_type', 128);
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('ip_address', 49)->nullable();
                $table->text('user_agent')->nullable();
                $table->text('details_json')->nullable();
                $table->index('event_at', 'users_audit_log_event_at_ix');
                $table->index(['user_id', 'event_at'], 'users_audit_log_user_id_event_at_ix');
            });
        }

        if (!$schema->hasTable('users_confirmations')) {
            $schema->create('users_confirmations', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->string('email', 249);
                $table->string('selector', 16);
                $table->string('token', 255);
                $table->unsignedInteger('expires');
                $table->unique('selector', 'users_confirmations_selector_uq');
                $table->index(['email', 'expires'], 'users_confirmations_email_expires_ix');
                $table->index('user_id', 'users_confirmations_user_id_ix');
            });
        }

        if (!$schema->hasTable('users_otps')) {
            $schema->create('users_otps', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('mechanism');
                $table->tinyInteger('single_factor')->default(0);
                $table->string('selector', 24);
                $table->string('token', 255);
                $table->unsignedInteger('expires_at')->nullable();
                $table->index(['user_id', 'mechanism'], 'users_otps_user_id_mechanism_ix');
                $table->index(['selector', 'user_id'], 'users_otps_selector_user_id_ix');
            });
        }

        if (!$schema->hasTable('users_remembered')) {
            $schema->create('users_remembered', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user');
                $table->string('selector', 24);
                $table->string('token', 255);
                $table->unsignedInteger('expires');
                $table->unique('selector', 'users_remembered_selector_uq');
                $table->index('user', 'users_remembered_user_ix');
            });
        }

        if (!$schema->hasTable('users_resets')) {
            $schema->create('users_resets', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user');
                $table->string('selector', 20);
                $table->string('token', 255);
                $table->unsignedInteger('expires');
                $table->unique('selector', 'users_resets_selector_uq');
                $table->index(['user', 'expires'], 'users_resets_user_expires_ix');
            });
        }

        if (!$schema->hasTable('users_throttling')) {
            $schema->create('users_throttling', static function (Blueprint $table): void {
                $table->string('bucket', 44)->primary();
                $table->float('tokens');
                $table->unsignedInteger('replenished_at');
                $table->unsignedInteger('expires_at');
                $table->index('expires_at', 'users_throttling_expires_at_ix');
            });
        }

        // 2. agents
        if (!$schema->hasTable('agents')) {
            $schema->create('agents', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->string('name', 100)->default('My Assistant');
                $table->text('description')->nullable();
                $table->string('recipe_id', 100)->nullable();
                $table->string('llm_provider', 50)->default('openai_compatible');
                $table->string('llm_model', 100)->default('gpt-4o');
                $table->string('llm_base_url', 255)->nullable();
                $table->unsignedTinyInteger('max_steps')->default(10);
                $table->tinyInteger('is_active')->default(1);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index('user_id', 'idx_agents_user_id');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // 3. tool_configurations
        if (!$schema->hasTable('tool_configurations')) {
            $schema->create('tool_configurations', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('tool_class', 200)->unique();
                $table->string('tool_name', 100);
                $table->text('settings')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index('tool_name', 'idx_tool_configurations_name');
            });
        }

        // 4. agent_tools
        if (!$schema->hasTable('agent_tools')) {
            $schema->create('agent_tools', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('agent_id');
                $table->string('tool_class', 200);
                $table->string('tool_name', 100);
                // TINYINT(1) nullable — intentionally NOT a boolean; three-state: 0/1/null
                $table->tinyInteger('auto_approve')->nullable()->default(null);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(['agent_id', 'tool_class'], 'uq_agent_tools');
                $table->index('tool_name', 'idx_agent_tools_tool_name');
                $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            });
        }

        // 5. agent_tool_overrides
        if (!$schema->hasTable('agent_tool_overrides')) {
            $schema->create('agent_tool_overrides', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('agent_id');
                $table->string('tool_class', 200);
                $table->text('settings');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(['agent_id', 'tool_class'], 'uq_agent_tool_overrides');
                $table->index('tool_class', 'idx_agent_tool_overrides_tool');
                $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            });
        }

        // 6. tasks
        if (!$schema->hasTable('tasks')) {
            $schema->create('tasks', static function (Blueprint $table): void {
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

        // 7. tool_calls
        if (!$schema->hasTable('tool_calls')) {
            $schema->create('tool_calls', static function (Blueprint $table): void {
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

        // 8. task_history — append-only, no updated_at
        if (!$schema->hasTable('task_history')) {
            $schema->create('task_history', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('task_id');
                $table->unsignedSmallInteger('sequence');
                $table->string('role', 20);
                $table->text('content')->nullable();
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
    }
}
