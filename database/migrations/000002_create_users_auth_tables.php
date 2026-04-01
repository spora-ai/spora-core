<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;


/**
 * delight-im/auth auxiliary tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('users_2fa', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('mechanism');
            $table->string('seed', 255)->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('expires_at')->nullable();
            $table->unique(['user_id', 'mechanism'], 'users_2fa_user_id_mechanism_uq');
        });

        Capsule::schema()->create('users_audit_log', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('event_at');
            $table->string('event_type', 128);
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('ip_address', 49)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('details_json')->nullable();
            $table->index('event_at', 'users_audit_log_event_at_ix');
            $table->index(['user_id', 'event_at'], 'users_audit_log_user_id_event_at_ix');
        });

        Capsule::schema()->create('users_confirmations', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('email', 249);
            $table->string('selector', 16);
            $table->string('token', 255);
            $table->unsignedInteger('expires');
            $table->unique('selector', 'users_confirmations_selector_uq');
            $table->index(['email', 'expires'], 'users_confirmations_email_expires_ix');
            $table->index('user_id', 'users_confirmations_user_id_ix');
        });

        Capsule::schema()->create('users_otps', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('mechanism');
            $table->tinyInteger('single_factor')->default(0);
            $table->string('selector', 24);
            $table->string('token', 255);
            $table->unsignedInteger('expires_at')->nullable();
            $table->index(['user_id', 'mechanism'], 'users_otps_user_id_mechanism_ix');
            $table->index(['selector', 'user_id'], 'users_otps_selector_user_id_ix');
        });

        Capsule::schema()->create('users_remembered', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user');
            $table->string('selector', 24);
            $table->string('token', 255);
            $table->unsignedInteger('expires');
            $table->unique('selector', 'users_remembered_selector_uq');
            $table->index('user', 'users_remembered_user_ix');
        });

        Capsule::schema()->create('users_resets', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user');
            $table->string('selector', 20);
            $table->string('token', 255);
            $table->unsignedInteger('expires');
            $table->unique('selector', 'users_resets_selector_uq');
            $table->index(['user', 'expires'], 'users_resets_user_expires_ix');
        });

        Capsule::schema()->create('users_throttling', static function (Blueprint $table): void {
            $table->string('bucket', 44)->primary();
            $table->float('tokens');
            $table->unsignedInteger('replenished_at');
            $table->unsignedInteger('expires_at');
            $table->index('expires_at', 'users_throttling_expires_at_ix');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('users_throttling');
        Capsule::schema()->dropIfExists('users_resets');
        Capsule::schema()->dropIfExists('users_remembered');
        Capsule::schema()->dropIfExists('users_otps');
        Capsule::schema()->dropIfExists('users_confirmations');
        Capsule::schema()->dropIfExists('users_audit_log');
        Capsule::schema()->dropIfExists('users_2fa');
    }
};
