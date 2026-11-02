<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Scoped API keys — one credential table for the agent, group and global
 * scopes.
 *
 * `agent_api_keys` becomes `api_keys`, and `agent_api_callbacks` becomes
 * `api_callbacks`: the outbox is written by all three scopes now, so a name
 * that says "agent" would be a lie.
 *
 * The tables are rebuilt rather than altered in place. `agent_api_keys.agent_id`
 * has to become nullable — a group key names no agent — and SQLite cannot
 * relax a NOT NULL constraint without recreating the table, which would drop
 * the table the callback outbox points at and take the outbox with it. So the
 * new tables are created, the rows copied, and the old ones dropped in the
 * order that never leaves a foreign key dangling:
 *
 *   1. create `api_keys`
 *   2. copy the agent-scoped rows into it, with `scope = 'agent'`
 *   3. create `api_callbacks` pointing at `api_keys`
 *   4. copy the outbox rows
 *   5. drop `agent_api_callbacks` — nothing points at it
 *   6. drop `agent_api_keys` — nothing points at it any more
 *
 * `scope` is a plain string, not a database enum: SQLite and MySQL spell
 * CHECK constraints differently and the universe is three values. The
 * invariants (`agent` ⇒ `agent_id`, `group` ⇒ `group_id`, `global` ⇒ neither)
 * are enforced in {@see \Spora\Auth\ApiKeyScope}, at mint time and at
 * authentication time.
 */
return new class extends Migration
{
    private const OLD_KEYS_TABLE = 'agent_api_keys';

    private const KEYS_TABLE = 'api_keys';

    private const OLD_CALLBACKS_TABLE = 'agent_api_callbacks';

    private const CALLBACKS_TABLE = 'api_callbacks';

    public function up(): void
    {
        $schema = Capsule::schema();

        // The table work is skipped once it has happened; the settings
        // rename is not, because it is idempotent on its own and a run that
        // stopped between the two would otherwise leave the old name behind.
        if (!$schema->hasTable(self::KEYS_TABLE)) {
            $this->createKeysTable();
            $this->copyKeys();

            $this->createCallbacksTable();
            $this->copyCallbacks();

            $this->dropLegacyTables();
        }

        $this->renameRateLimitSetting();
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::OLD_KEYS_TABLE)) {
            $this->createLegacyKeysTable();
            $this->copyAgentScopedKeysBack();

            $this->createLegacyCallbacksTable();
            $this->copyCallbacksBack();

            $this->dropScopedTables();
        }

        $this->restoreRateLimitSetting();
    }

    private function createKeysTable(): void
    {
        Capsule::schema()->create(self::KEYS_TABLE, static function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('scope', 10);
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('name', 100);
            $table->string('secret_hash', 64);
            $table->dateTime('last_used_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('scope');
            $table->index('agent_id');
            $table->index('group_id');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->foreign('group_id')->references('id')->on('groups')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Every key that existed before this migration is agent-scoped: the
     * group and global scopes did not exist yet.
     */
    private function copyKeys(): void
    {
        if (!Capsule::schema()->hasTable(self::OLD_KEYS_TABLE)) {
            return;
        }

        Capsule::table(self::OLD_KEYS_TABLE)->orderBy('id')->chunk(200, static function ($rows): void {
            foreach ($rows as $row) {
                Capsule::table(self::KEYS_TABLE)->insert([
                    'id'           => (string) $row->id,
                    'scope'        => 'agent',
                    'agent_id'     => $row->agent_id === null ? null : (int) $row->agent_id,
                    'group_id'     => null,
                    'name'         => (string) $row->name,
                    'secret_hash'  => (string) $row->secret_hash,
                    'last_used_at' => $row->last_used_at,
                    'created_by'   => $row->created_by === null ? null : (int) $row->created_by,
                    'created_at'   => $row->created_at,
                    'updated_at'   => $row->updated_at,
                ]);
            }
        });
    }

    private function createCallbacksTable(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable(self::CALLBACKS_TABLE) || !$schema->hasTable(self::OLD_CALLBACKS_TABLE)) {
            return;
        }

        $schema->create(self::CALLBACKS_TABLE, static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chat_id')->unique();
            $table->string('key_id', 32);
            $table->unsignedBigInteger('agent_id');
            // 2048 matches the ceiling `SsrfGuard` refuses a `callback_url` above.
            $table->string('url', 2048);
            $table->string('pending_status', 30)->nullable();
            $table->string('delivered_status', 30)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            // NULL means "not owed right now": disarmed after a terminal
            // delivery, or dead-lettered after MAX_ATTEMPTS. The sweep selects
            // `next_attempt_at IS NOT NULL AND <= now`, so a NULL row is never
            // read again — and the recorder arms it, never leaves it NULL.
            $table->dateTime('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            // The sweep's only query: owed rows whose backoff has elapsed.
            $table->index(['next_attempt_at', 'id']);
            $table->index('agent_id');
            $table->foreign('chat_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('key_id')->references('id')->on(self::KEYS_TABLE)->onDelete('cascade');
        });
    }

    private function copyCallbacks(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::OLD_CALLBACKS_TABLE) || !$schema->hasTable(self::CALLBACKS_TABLE)) {
            return;
        }

        Capsule::table(self::OLD_CALLBACKS_TABLE)->orderBy('id')->chunk(200, static function ($rows): void {
            foreach ($rows as $row) {
                Capsule::table(self::CALLBACKS_TABLE)->insert([
                    'id'               => (int) $row->id,
                    'chat_id'         => (int) $row->chat_id,
                    'key_id'          => (string) $row->key_id,
                    'agent_id'        => (int) $row->agent_id,
                    'url'             => (string) $row->url,
                    'pending_status'  => $row->pending_status,
                    'delivered_status' => $row->delivered_status,
                    'attempts'        => (int) $row->attempts,
                    'next_attempt_at' => $row->next_attempt_at,
                    'last_status_code' => $row->last_status_code === null ? null : (int) $row->last_status_code,
                    'last_error'      => $row->last_error,
                    'delivered_at'    => $row->delivered_at,
                    'created_at'      => $row->created_at,
                    'updated_at'      => $row->updated_at,
                ]);
            }
        });
    }

    private function dropLegacyTables(): void
    {
        $schema = Capsule::schema();

        // The outbox first: it is the table that points at the keys, and a
        // dropped parent cascades its children away.
        if ($schema->hasTable(self::OLD_CALLBACKS_TABLE)) {
            $schema->drop(self::OLD_CALLBACKS_TABLE);
        }

        if ($schema->hasTable(self::OLD_KEYS_TABLE)) {
            $schema->drop(self::OLD_KEYS_TABLE);
        }
    }

    /**
     * The rate limit is now shared by all three scopes, so the setting is
     * renamed. The stored value moves with it — unless the operator has
     * already saved a value under the new name, which then wins.
     */
    private function renameRateLimitSetting(): void
    {
        $this->moveSetting('SPORA_AGENT_API_RATE_LIMIT_MAX', 'SPORA_API_RATE_LIMIT_MAX');
    }

    private function restoreRateLimitSetting(): void
    {
        $this->moveSetting('SPORA_API_RATE_LIMIT_MAX', 'SPORA_AGENT_API_RATE_LIMIT_MAX');
    }

    private function moveSetting(string $from, string $to): void
    {
        if (!Capsule::schema()->hasTable('settings')) {
            return;
        }

        $existing = Capsule::table('settings')
            ->where('group_key', 'framework')
            ->where('setting_key', $to)
            ->exists();

        if ($existing) {
            return;
        }

        Capsule::table('settings')
            ->where('group_key', 'framework')
            ->where('setting_key', $from)
            ->update(['setting_key' => $to]);
    }

    private function createLegacyKeysTable(): void
    {
        Capsule::schema()->create(self::OLD_KEYS_TABLE, static function (Blueprint $table): void {
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
    }

    /**
     * Rolling back keeps the agent-scoped keys and drops the rest: the old
     * table has no room for a group id or a scope.
     */
    private function copyAgentScopedKeysBack(): void
    {
        if (!Capsule::schema()->hasTable(self::KEYS_TABLE)) {
            return;
        }

        Capsule::table(self::KEYS_TABLE)
            ->where('scope', 'agent')
            ->whereNotNull('agent_id')
            ->orderBy('id')
            ->chunk(200, static function ($rows): void {
                foreach ($rows as $row) {
                    Capsule::table(self::OLD_KEYS_TABLE)->insert([
                        'id'           => (string) $row->id,
                        'agent_id'     => (int) $row->agent_id,
                        'name'         => (string) $row->name,
                        'secret_hash'  => (string) $row->secret_hash,
                        'last_used_at' => $row->last_used_at,
                        'created_by'   => $row->created_by === null ? null : (int) $row->created_by,
                        'created_at'   => $row->created_at,
                        'updated_at'   => $row->updated_at,
                    ]);
                }
            });
    }

    private function createLegacyCallbacksTable(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable(self::OLD_CALLBACKS_TABLE) || !$schema->hasTable(self::CALLBACKS_TABLE)) {
            return;
        }

        $schema->create(self::OLD_CALLBACKS_TABLE, static function (Blueprint $table): void {
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
            $table->foreign('key_id')->references('id')->on(self::OLD_KEYS_TABLE)->onDelete('cascade');
        });
    }

    private function copyCallbacksBack(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::CALLBACKS_TABLE) || !$schema->hasTable(self::OLD_CALLBACKS_TABLE)) {
            return;
        }

        Capsule::table(self::CALLBACKS_TABLE)->orderBy('id')->chunk(200, static function ($rows): void {
            foreach ($rows as $row) {
                Capsule::table(self::OLD_CALLBACKS_TABLE)->insert([
                    'id'               => (int) $row->id,
                    'chat_id'         => (int) $row->chat_id,
                    'key_id'          => (string) $row->key_id,
                    'agent_id'        => (int) $row->agent_id,
                    'url'             => (string) $row->url,
                    'pending_status'  => $row->pending_status,
                    'delivered_status' => $row->delivered_status,
                    'attempts'        => (int) $row->attempts,
                    'next_attempt_at' => $row->next_attempt_at,
                    'last_status_code' => $row->last_status_code === null ? null : (int) $row->last_status_code,
                    'last_error'      => $row->last_error,
                    'delivered_at'    => $row->delivered_at,
                    'created_at'      => $row->created_at,
                    'updated_at'      => $row->updated_at,
                ]);
            }
        });
    }

    private function dropScopedTables(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable(self::CALLBACKS_TABLE)) {
            $schema->drop(self::CALLBACKS_TABLE);
        }

        if ($schema->hasTable(self::KEYS_TABLE)) {
            $schema->drop(self::KEYS_TABLE);
        }
    }
};
