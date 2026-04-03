<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('tool_calls', function (Blueprint $table) {
            $table->index(['task_id', 'provider_call_id'], 'idx_tc_task_provider');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('tool_calls', function (Blueprint $table) {
            $table->dropIndex('idx_tc_task_provider');
        });
    }
};
