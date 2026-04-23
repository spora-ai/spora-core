<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('tool_calls', static function (Blueprint $table): void {
            $table->string('operation', 100)->nullable()->after('tool_type');
            $table->string('operation_description', 500)->nullable()->after('operation');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('tool_calls', static function (Blueprint $table): void {
            $table->dropColumn(['operation', 'operation_description']);
        });
    }
};
