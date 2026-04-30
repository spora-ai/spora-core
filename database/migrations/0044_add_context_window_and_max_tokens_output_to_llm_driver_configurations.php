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
            $table->unsignedInteger('context_window')->nullable()->after('settings');
            $table->unsignedInteger('max_tokens_output')->nullable()->after('context_window');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('llm_driver_configurations', static function (Blueprint $table): void {
            $table->dropColumn(['context_window', 'max_tokens_output']);
        });
    }
};
