<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('users', static function (Blueprint $table): void {
            $table->string('name', 100)->nullable()->after('username');
            $table->date('date_of_birth')->nullable()->after('name');
            $table->text('about_me')->nullable()->after('date_of_birth');
            $table->decimal('height_cm', 5, 2)->nullable()->after('about_me');
            $table->decimal('weight_kg', 5, 2)->nullable()->after('height_cm');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn(['name', 'date_of_birth', 'about_me', 'height_cm', 'weight_kg']);
        });
    }
};
