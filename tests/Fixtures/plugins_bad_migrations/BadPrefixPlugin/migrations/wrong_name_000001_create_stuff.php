<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Capsule::schema()->create('bad_prefix_stuff', static function (Blueprint $table): void {
            $table->bigIncrements('id');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('bad_prefix_stuff');
    }
};
