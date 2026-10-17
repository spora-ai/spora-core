<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Global settings store — the operator-editable layer of `Config`.
 *
 * Precedence is `registry defaults < config.php < .env < this table`. The
 * last layer exists so an admin can change a runtime setting from the SPA
 * instead of editing `.env` on a shared host. Keys are the same `SPORA_*`
 * strings used by `.env` and `config.php`, so there is only ever one name to
 * learn.
 *
 * `bootOnly` keys (DB credentials, secret-key path, log path) are never
 * written here: they are read before this table can be opened. See
 * `SettingsRegistry` and `Config::applySettingsOverlay()`.
 *
 * `encrypted` is an explicit column rather than a `looksEncrypted()` sniff so
 * a row is unambiguous in SQL and in a support query — sniffing misreads a
 * short plaintext secret as ciphertext and decrypts it into garbage.
 *
 * `updated_by` is `ON DELETE SET NULL`: deleting the account that set a
 * setting must not delete the setting itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('settings', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('group_key', 64);
            $table->string('setting_key', 128);
            $table->text('value');
            $table->boolean('encrypted')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->datetime('created_at')->nullable();
            $table->datetime('updated_at')->nullable();

            $table->unique(['group_key', 'setting_key'], 'uq_settings_group_key');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('settings');
    }
};