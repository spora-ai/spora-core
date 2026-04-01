<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;


return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('agents', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100)->default('My Assistant');
            $table->text('description')->nullable();
            $table->string('recipe_id', 100)->nullable();
            $table->string('llm_provider', 50)->default('openai_compatible');
            $table->string('llm_model', 100)->default('gpt-4o');
            $table->string('llm_base_url', 255)->nullable();
            $table->unsignedTinyInteger('max_steps')->default(10);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('user_id', 'idx_agents_user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('agents');
    }
};
