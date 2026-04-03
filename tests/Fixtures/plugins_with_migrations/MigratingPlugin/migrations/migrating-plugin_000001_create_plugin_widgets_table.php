<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Capsule::schema()->create('plugin_widgets', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('plugin_widgets');
    }
};
