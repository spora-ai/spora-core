<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;


return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('tool_configurations', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tool_class', 200)->unique();
            $table->string('tool_name', 100);
            $table->text('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('tool_name', 'idx_tool_configurations_name');
        });

        Capsule::schema()->create('agent_tools', static function (Blueprint $table): void {
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

        Capsule::schema()->create('agent_tool_overrides', static function (Blueprint $table): void {
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

    public function down(): void
    {
        Capsule::schema()->dropIfExists('agent_tool_overrides');
        Capsule::schema()->dropIfExists('agent_tools');
        Capsule::schema()->dropIfExists('tool_configurations');
    }
};
