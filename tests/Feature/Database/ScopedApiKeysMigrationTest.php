<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Migration 0098 — the key table becomes `api_keys` and the outbox becomes
 * `api_callbacks`.
 *
 * The tables are rebuilt rather than altered in place, because
 * `agent_api_keys.agent_id` has to become nullable and SQLite cannot relax
 * a NOT NULL constraint without recreating the table — which would drop
 * the table the outbox points at and take the outbox with it.
 *
 * What this pins is that the rebuild loses nothing: a key minted before the
 * migration still authenticates, and the callback it owes is still owed.
 */
beforeEach(function (): void {
    // The test builds its own schema → connect-only, no installer.
    TestDatabaseFactory::freshConnectionOnly();

    Capsule::schema()->create('users', static function (Blueprint $table): void {
        $table->increments('id');
        $table->string('email');
        $table->string('name');
        $table->timestamps();
    });

    Capsule::schema()->create('groups', static function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
        $table->text('description')->nullable();
        $table->unsignedBigInteger('created_by_user_id')->nullable();
        $table->timestamps();
    });

    Capsule::schema()->create('agents', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedBigInteger('principal_id');
        $table->string('name');
        $table->timestamps();
    });

    Capsule::schema()->create('tasks', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedBigInteger('agent_id');
        $table->text('user_prompt')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
    });

    Capsule::schema()->create('settings', static function (Blueprint $table): void {
        $table->increments('id');
        $table->string('group_key', 64);
        $table->string('setting_key', 128);
        $table->text('value');
        $table->boolean('encrypted')->default(false);
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->datetime('created_at')->nullable();
        $table->datetime('updated_at')->nullable();
        $table->unique(['group_key', 'setting_key'], 'uq_settings_group_key');
    });

    // The two legacy tables, in the shape migration 0093 and 0094 left them.
    Capsule::schema()->create('agent_api_keys', static function (Blueprint $table): void {
        $table->string('id', 32)->primary();
        $table->unsignedBigInteger('agent_id');
        $table->string('name', 100);
        $table->string('secret_hash', 64);
        $table->dateTime('last_used_at')->nullable();
        $table->unsignedBigInteger('created_by')->nullable();
        $table->timestamps();
        $table->index('agent_id');
        $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
        $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
    });

    Capsule::schema()->create('agent_api_callbacks', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('chat_id')->unique();
        $table->string('key_id', 32);
        $table->unsignedBigInteger('agent_id');
        $table->string('url', 2048);
        $table->string('pending_status', 30)->nullable();
        $table->string('delivered_status', 30)->nullable();
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->dateTime('next_attempt_at')->nullable();
        $table->unsignedSmallInteger('last_status_code')->nullable();
        $table->string('last_error', 500)->nullable();
        $table->dateTime('delivered_at')->nullable();
        $table->timestamps();
        $table->index(['next_attempt_at', 'id']);
        $table->index('agent_id');
        $table->foreign('chat_id')->references('id')->on('tasks')->onDelete('cascade');
        $table->foreign('key_id')->references('id')->on('agent_api_keys')->onDelete('cascade');
    });
});

test('the key minted before the migration still authenticates', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com', 'name' => 'A']);
    Capsule::table('agents')->insert(['id' => 1, 'principal_id' => 1, 'name' => 'Agent']);

    $id = str_repeat('a', 32);
    $secret = str_repeat('b', 64);

    Capsule::table('agent_api_keys')->insert([
        'id' => $id,
        'agent_id' => 1,
        'name' => 'legacy',
        'secret_hash' => hash('sha256', $secret),
        'created_by' => 1,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0098_scoped_api_keys.php';
    $migration->up();

    $key = Spora\Models\ApiKey::find($id);

    expect($key)->not->toBeNull();
    expect($key->scope)->toBe('agent');
    expect((int) $key->agent_id)->toBe(1);
    expect($key->group_id)->toBeNull();
    // The digest is what makes the key work, and it has to have survived.
    expect($key->secret_hash)->toBe(hash('sha256', $secret));

    $issuer = new Spora\Auth\ApiKeyIssuer();
    expect($issuer->verify($secret, $key)[0])->toBeTrue();
});

test('the callback the old key owed is still owed', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com', 'name' => 'A']);
    Capsule::table('agents')->insert(['id' => 1, 'principal_id' => 1, 'name' => 'Agent']);
    Capsule::table('tasks')->insert(['id' => 1, 'agent_id' => 1, 'user_prompt' => 'hi', 'status' => 'QUEUED']);

    $id = str_repeat('a', 32);

    Capsule::table('agent_api_keys')->insert([
        'id' => $id,
        'agent_id' => 1,
        'name' => 'legacy',
        'secret_hash' => str_repeat('b', 64),
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    Capsule::table('agent_api_callbacks')->insert([
        'chat_id' => 1,
        'key_id' => $id,
        'agent_id' => 1,
        'url' => 'https://hooks.example.test/spora',
        'pending_status' => 'COMPLETED',
        'attempts' => 0,
        'next_attempt_at' => '2026-01-03 00:00:00',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0098_scoped_api_keys.php';
    $migration->up();

    $callback = Spora\Models\ApiCallback::where('chat_id', 1)->first();

    expect($callback)->not->toBeNull();
    expect($callback->key_id)->toBe($id);
    expect($callback->url)->toBe('https://hooks.example.test/spora');
    // The outbox still points at the key table, or deleting the key would
    // leave a row nothing can deliver.
    expect($callback->key)->not->toBeNull();
});

test('a group key can be stored next to an agent key', function (): void {
    Capsule::table('groups')->insert(['id' => 1, 'name' => 'G']);
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com', 'name' => 'A']);

    $migration = require __DIR__ . '/../../../database/migrations/0098_scoped_api_keys.php';
    $migration->up();

    Spora\Models\ApiKey::create([
        'id' => str_repeat('c', 32),
        'scope' => 'group',
        'agent_id' => null,
        'group_id' => 1,
        'name' => 'group key',
        'secret_hash' => str_repeat('d', 64),
        'created_by' => 1,
    ]);

    $key = Spora\Models\ApiKey::find(str_repeat('c', 32));

    expect($key)->not->toBeNull();
    expect($key->agent_id)->toBeNull();
    expect((int) $key->group_id)->toBe(1);
});

test('the rate-limit setting moves with its new name', function (): void {
    Capsule::table('settings')->insert([
        'group_key' => 'framework',
        'setting_key' => 'SPORA_AGENT_API_RATE_LIMIT_MAX',
        'value' => '120',
        'encrypted' => false,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0098_scoped_api_keys.php';
    $migration->up();

    $stored = Capsule::table('settings')->where('group_key', 'framework')->get();

    expect($stored)->toHaveCount(1);
    expect($stored->first()->setting_key)->toBe('SPORA_API_RATE_LIMIT_MAX');
    // The operator's value has to survive the rename.
    expect($stored->first()->value)->toBe('120');
});

test('a value already stored under the new name is not overwritten', function (): void {
    Capsule::table('settings')->insert([
        'group_key' => 'framework',
        'setting_key' => 'SPORA_AGENT_API_RATE_LIMIT_MAX',
        'value' => '120',
        'encrypted' => false,
    ]);
    Capsule::table('settings')->insert([
        'group_key' => 'framework',
        'setting_key' => 'SPORA_API_RATE_LIMIT_MAX',
        'value' => '30',
        'encrypted' => false,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0098_scoped_api_keys.php';
    $migration->up();

    $effective = Capsule::table('settings')
        ->where('group_key', 'framework')
        ->where('setting_key', 'SPORA_API_RATE_LIMIT_MAX')
        ->first();

    // The operator's value under the new name is the one that counts, and
    // the rename must not clobber it with the old one.
    expect($effective)->not->toBeNull();
    expect($effective->value)->toBe('30');
});

test('down puts the agent-scoped keys back', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com', 'name' => 'A']);
    Capsule::table('agents')->insert(['id' => 1, 'principal_id' => 1, 'name' => 'Agent']);

    $migration = require __DIR__ . '/../../../database/migrations/0098_scoped_api_keys.php';
    $migration->up();

    Spora\Models\ApiKey::create([
        'id' => str_repeat('a', 32),
        'scope' => 'agent',
        'agent_id' => 1,
        'group_id' => null,
        'name' => 'agent key',
        'secret_hash' => str_repeat('b', 64),
    ]);

    $migration->down();

    expect(Capsule::schema()->hasTable('agent_api_keys'))->toBeTrue();
    expect(Capsule::schema()->hasTable('api_keys'))->toBeFalse();

    $restored = Capsule::table('agent_api_keys')->first();
    expect($restored->id)->toBe(str_repeat('a', 32));
    expect((int) $restored->agent_id)->toBe(1);
});
