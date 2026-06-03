<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('agent_tools', static function (Blueprint $table): void {
            $table->dropColumn('auto_approve');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('agent_tools', static function (Blueprint $table): void {
            $table->tinyInteger('auto_approve')->nullable()->default(null);
        });
    }
};
