<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('mail_templates', static function (Blueprint $table): void {
            $table->renameColumn('body_text', 'body');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('mail_templates', static function (Blueprint $table): void {
            $table->renameColumn('body', 'body_text');
        });
    }
};