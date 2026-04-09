<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('tasks', function (Blueprint $table) {
            // Composite index for the reaper query: WHERE status = 'RUNNING' AND updated_at < $cutoff
            $table->index(['status', 'updated_at'], 'idx_tasks_status_updated');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('tasks', function (Blueprint $table) {
            $table->dropIndex('idx_tasks_status_updated');
        });
    }
};
