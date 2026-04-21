<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Tasks: retry tracking columns
        Capsule::schema()->table('tasks', static function ($table): void {
            $table->unsignedBigInteger('retry_of_task_id')->nullable()->after('max_steps');
            $table->unsignedSmallInteger('retry_count')->default(0)->after('retry_of_task_id');
            $table->timestamp('retry_after')->nullable()->after('retry_count');
            $table->string('failure_reason', 1000)->nullable()->change();
        });

        // Agents: auto-retry configuration
        Capsule::schema()->table('agents', static function ($table): void {
            $table->unsignedSmallInteger('retry_after_minutes')->default(0)->after('max_steps');
            $table->unsignedSmallInteger('max_retries')->default(0)->after('retry_after_minutes');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('tasks', static function ($table): void {
            $table->dropColumn(['retry_of_task_id', 'retry_count', 'retry_after']);
        });

        Capsule::schema()->table('agents', static function ($table): void {
            $table->dropColumn(['retry_after_minutes', 'max_retries']);
        });
    }
};
