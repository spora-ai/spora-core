<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('agents', static function (Blueprint $table): void {
            // Add FK to LLM driver configurations (nullable — existing agents keep using defaults)
            $table->unsignedBigInteger('llm_driver_config_id')->nullable()->after('is_active');
            $table->foreign('llm_driver_config_id')
                ->references('id')
                ->on('llm_driver_configurations')
                ->onDelete('set null');

            // Drop deprecated columns (model + base_url now live in LLMDriverConfiguration)
            $table->dropColumn(['llm_provider', 'llm_model', 'llm_base_url']);
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('agents', static function (Blueprint $table): void {
            $table->string('llm_provider', 50)->default('openai_compatible')->after('is_active');
            $table->string('llm_model', 100)->default('gpt-4o')->after('llm_provider');
            $table->string('llm_base_url', 255)->nullable()->after('llm_model');
            $table->dropForeign(['llm_driver_config_id']);
            $table->dropColumn('llm_driver_config_id');
        });
    }
};
