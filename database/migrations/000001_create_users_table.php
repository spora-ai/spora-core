<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;


return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('users', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('email', 249)->unique();
            $table->string('password', 255);
            $table->string('username', 100)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('verified')->default(0);
            $table->tinyInteger('resettable')->default(1);
            $table->unsignedInteger('roles_mask')->default(0);
            $table->unsignedInteger('registered');
            $table->unsignedInteger('last_login')->nullable();
            $table->unsignedMediumInteger('force_logout')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('users');
    }
};
