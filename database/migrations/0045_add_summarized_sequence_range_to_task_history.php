<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('task_history', static function (Blueprint $table): void {
            $table->string('summarized_sequence_range', 50)->nullable()->after('sequence');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('task_history', static function (Blueprint $table): void {
            $table->dropColumn('summarized_sequence_range');
        });
    }
};
