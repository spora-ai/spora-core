<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('tasks', function ($table) {
            $table->string('error_code', 30)->nullable()->after('failure_reason');
            $table->text('error_message')->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('tasks', function ($table) {
            $table->dropColumn(['error_code', 'error_message']);
        });
    }
};