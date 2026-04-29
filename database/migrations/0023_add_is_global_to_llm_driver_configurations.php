<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('llm_driver_configurations', static function (Blueprint $table): void {
            $table->boolean('is_global')->default(false)->after('is_default');
            $table->index(['is_global', 'is_default'], 'llm_configs_global_default_idx');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('llm_driver_configurations', static function (Blueprint $table): void {
            $table->dropIndex('llm_configs_global_default_idx');
            $table->dropColumn('is_global');
        });
    }
};