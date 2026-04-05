<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('llm_driver_configurations', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100);
            $table->string('driver_class', 200);
            $table->text('settings')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('driver_class', 'idx_llm_driver_configurations_driver_class');
            $table->index('user_id', 'idx_llm_driver_configurations_user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('llm_driver_configurations');
    }
};
