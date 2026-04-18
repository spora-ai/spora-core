<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('tasks', static function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_task_id')->nullable()->after('max_steps');
            $table->foreign('parent_task_id')
                ->references('id')
                ->on('tasks')
                ->onDelete('cascade');
        });

        Capsule::schema()->table('agents', static function (Blueprint $table): void {
            $table->boolean('allow_followup')->default(true)->after('max_steps');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('tasks', static function (Blueprint $table): void {
            $table->dropForeign(['parent_task_id']);
            $table->dropColumn('parent_task_id');
        });

        Capsule::schema()->table('agents', static function (Blueprint $table): void {
            $table->dropColumn('allow_followup');
        });
    }
};