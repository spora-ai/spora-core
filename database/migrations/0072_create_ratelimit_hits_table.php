<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * DB-backed rate limiter for `/tick` and `/housekeeping`.
 *
 * Each row records one hit at `hit_at`; the handler counts rows in a
 * sliding window (e.g. the last 60s) keyed by `key` (typically the
 * route name + caller identity). The composite PK keeps insert hot
 * while allowing `DELETE … WHERE hit_at < ?` GC to drain old rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('ratelimit_hits')) {
            return;
        }

        $schema->create('ratelimit_hits', static function (Blueprint $table): void {
            $table->string('key', 100);
            $table->dateTime('hit_at');
            $table->primary(['key', 'hit_at']);
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('ratelimit_hits');
    }
};