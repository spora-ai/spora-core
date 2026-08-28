<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Single-row DB-backed shared lock for `/worker/housekeeping`.
 *
 * Only one browser tab may run housekeeping at a time across the cluster;
 * the handler uses this row to coordinate (CAS on `claimed_until`). The
 * `id` column is always 1 — a single-row pattern that lets us treat the
 * whole table as a mutex without a UNIQUE constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('worker_housekeeping_locks')) {
            return;
        }

        $schema->create('worker_housekeeping_locks', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->dateTime('claimed_until');
            $table->integer('claimed_by');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('worker_housekeeping_locks');
    }
};