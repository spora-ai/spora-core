<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Spora\Core\Database;
use Spora\Speech\OpenAiCompatibleTranscriber;

/**
 * Migration 0083 backfill — speech_provider_configurations seeded
 * from tool_configurations (global) + tool_user_settings
 * (per-user / per-group). The data migration runs in PHP so it can
 * enumerate registered SpeechToTextProviderInterface classes; only
 * the core-shipped OpenAiCompatibleTranscriber is visible to the
 * CLI boot, so the test exercises that single class. Plugin-
 * contributed STT rows are explicitly out of scope (see the
 * migration's docblock).
 *
 * The data migration lived in 0087 in the previous branch layout;
 * folding it into 0083 keeps the unified migration set contiguous
 * (0081 → 0084).
 */
beforeEach(function (): void {
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    // Minimal schema for the migration to find rows in.
    Capsule::schema()->create('tool_configurations', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->string('tool_class', 200)->unique();
        $t->string('tool_name', 100);
        $t->text('settings')->nullable();
        $t->boolean('is_default')->default(false);
        $t->timestamps();
    });
    Capsule::schema()->create('tool_user_settings', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('principal_id');
        $t->string('tool_class', 200);
        $t->text('settings')->nullable();
        $t->boolean('is_default')->default(false);
        $t->timestamps();
    });
    Capsule::schema()->create('principals', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->enum('type', ['user', 'group']);
        $t->unsignedBigInteger('user_id')->nullable();
        $t->unsignedBigInteger('group_id')->nullable();
        $t->timestamps();
    });

    // Migration 0082 — the table the migration copies INTO.
    Capsule::schema()->create('speech_provider_configurations', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('principal_id')->nullable();
        $t->string('provider_class', 200);
        $t->string('display_name', 100);
        $t->text('settings')->nullable();
        $t->boolean('is_default')->default(false);
        $t->boolean('is_global')->default(false);
        $t->timestamps();
    });
});

test('global row in tool_configurations is copied with is_global=true', function (): void {
    $now = date('Y-m-d H:i:s');
    Capsule::table('tool_configurations')->insert([
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'tool_name' => 'OpenAI Compat',
        'settings' => '{"api_key":"sk-global"}',
        'is_default' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0083_add_is_default_to_tool_config_tables.php';
    $migration->up();

    $row = Capsule::table('speech_provider_configurations')->first();
    expect($row)->not->toBeNull();
    expect($row->provider_class)->toBe(OpenAiCompatibleTranscriber::class);
    expect((int) $row->is_global)->toBe(1);
    expect((int) $row->is_default)->toBe(1);
    expect($row->principal_id)->toBeNull();
    expect($row->settings)->toBe('{"api_key":"sk-global"}');
});

test('per-user row in tool_user_settings is copied with is_global=false', function (): void {
    $now = date('Y-m-d H:i:s');
    Capsule::table('principals')->insert([
        'id' => 1,
        'type' => 'user',
        'user_id' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => 1,
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'settings' => '{"api_key":"sk-personal"}',
        'is_default' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0083_add_is_default_to_tool_config_tables.php';
    $migration->up();

    $row = Capsule::table('speech_provider_configurations')->first();
    expect($row)->not->toBeNull();
    expect($row->provider_class)->toBe(OpenAiCompatibleTranscriber::class);
    expect((int) $row->is_global)->toBe(0);
    expect((int) $row->principal_id)->toBe(1);
});

test('non-STT rows in the legacy tables are NOT backfilled', function (): void {
    $now = date('Y-m-d H:i:s');
    Capsule::table('tool_configurations')->insert([
        'tool_class' => 'Some\\Email\\Tool\\NotSpeech',
        'tool_name' => 'Email',
        'settings' => '{}',
        'is_default' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => 1,
        'tool_class' => 'Some\\Calendar\\Tool\\NotSpeech',
        'settings' => '{}',
        'is_default' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0083_add_is_default_to_tool_config_tables.php';
    $migration->up();

    expect(Capsule::table('speech_provider_configurations')->count())->toBe(0);
});

test('idempotency: re-running the migration does not duplicate rows', function (): void {
    $now = date('Y-m-d H:i:s');
    Capsule::table('tool_configurations')->insert([
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'tool_name' => 'OpenAI Compat',
        'settings' => '{}',
        'is_default' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0083_add_is_default_to_tool_config_tables.php';
    $migration->up();
    $migration->up();

    expect(Capsule::table('speech_provider_configurations')->count())->toBe(1);
});
