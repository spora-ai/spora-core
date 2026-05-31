<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('user_preferences', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('preferred_llm_config_id')->nullable();
            $table->datetime('created_at')->nullable();
            $table->datetime('updated_at')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('preferred_llm_config_id')->references('id')->on('llm_driver_configurations')->onDelete('set null');
            $table->unique('user_id');
        });

        // Backfill user preferences for existing default llm configurations
        $usersWithDefaults = Capsule::table('llm_driver_configurations')
            ->where('is_default', true)
            ->whereNotNull('user_id')
            ->get(['user_id', 'id']);

        foreach ($usersWithDefaults as $config) {
            Capsule::table('user_preferences')->insert([
                'user_id' => $config->user_id,
                'preferred_llm_config_id' => $config->id,
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);
        }
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('user_preferences');
    }
};