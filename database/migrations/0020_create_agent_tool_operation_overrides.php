<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('agent_tool_operation_overrides', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id');
            $table->string('tool_class', 200);
            $table->string('operation', 100);
            // TINYINT(1) nullable — three-state: 0=disabled/false, 1=enabled/true, null=use default
            $table->tinyInteger('enabled')->nullable()->default(null);
            $table->tinyInteger('default_requires_approval')->nullable()->default(null);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['agent_id', 'tool_class', 'operation'], 'uq_agent_tool_op_override');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('agent_tool_operation_overrides');
    }
};
