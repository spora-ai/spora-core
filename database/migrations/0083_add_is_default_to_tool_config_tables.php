<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;

/**
 * Two pieces of the speech-storage seam:
 *
 *   1. Add `is_default` to the two speech-config tables so the operator
 *      can mark one global row + one per-principal row per registered
 *      STT class as the default.
 *
 *      Invariant: at most one `is_default = true` per (scope, principal_id,
 *      tool_class) group is service-enforced (see
 *      {@see \Spora\Services\SpeechProviderConfigService::setDefaultConfig()}).
 *      Mirrors the LLM-side pattern in migration 0011 — `is_default` on
 *      `llm_driver_configurations` was the original model; the cascade in
 *      {@see \Spora\Speech\SpeechToTextRegistry::configuredProvider()} consumes
 *      the column on tier 4 (global default) plus tier 2 / 3 (per-principal
 *      preferred class lookup, which itself is stored on
 *      `principal_preferences.preferred_speech_provider_class` — see
 *      migration 0084).
 *
 *      Indexes:
 *        - `idx_tool_configurations_default` on `is_default` — speeds the
 *          tier-4 lookup (`WHERE tool_class IN (…) AND is_default = true`).
 *        - `idx_tool_user_settings_default` on `(principal_id, is_default)`
 *          — speeds the tier-2 / tier-3 lookup of "does this principal have
 *          a default config?".
 *
 *      Idempotency:
 *        Every step gates on `hasColumn()` / `indexExists()` from
 *        {@see \Spora\Core\Database\MigrationHelpers} so re-running this
 *        migration after a partial failure is a no-op. No backfill — the
 *        default `false` for `is_default` keeps existing rows out of the
 *        default slot, so no operator-visible state changes at upgrade time.
 *
 *   2. Backfill `speech_provider_configurations` from the legacy speech
 *      storage in `tool_configurations` (global scope) and
 *      `tool_user_settings` (per-user / per-group scope).
 *
 *      Why this step runs in PHP, not SQL:
 *
 *        The legacy tables hold rows whose `tool_class` is the FQCN of a
 *        `SpeechToTextProviderInterface` implementation. We only want
 *        to copy the rows for registered STT classes — a non-speech
 *        `tool_configurations` row (e.g. an email tool global config)
 *        must NOT be backfilled because it would pollute the
 *        `speech_provider_configurations` table with rows that the new
 *        cascade would treat as "an STT config exists for class Email",
 *        and the registry would then request a transcribe against a
 *        non-existent provider. The list of registered STT classes is
 *        discovered at runtime via {@see SpeechToTextRegistry::all()}.
 *
 *      Scope / why the registry is built with hard-coded classes:
 *
 *        Migrations run before the DI container / plugin loader is
 *        wired, so we cannot ask "what STT classes are registered right
 *        now?" — plugin-contributed classes are not visible to a fresh
 *        CLI boot. Only the core-shipped
 *        {@see OpenAiCompatibleTranscriber} can be safely backfilled
 *        here. Plugin-contributed classes (e.g. spora-plugin-muse's
 *        MuseTranscribeProvider) are left in place in the legacy tables
 *        so they remain readable — the operator is expected to either
 *        (a) leave the legacy rows alone (the cascade ignores them,
 *        but the operator can still see them in
 *        /api/v1/tool-configurations) or (b) re-create the equivalent
 *        row via the new `POST /api/v1/speech/provider-configs`
 *        endpoint, at which point the legacy row becomes redundant.
 *
 *      Encryption compatibility:
 *
 *        Settings on both legacy tables are encrypted via the same
 *        `SecurityManager` the `speech_provider_configurations`
 *        persistence layer uses. We copy the encrypted blob verbatim —
 *        no decode / re-encode, so the migration's blast radius is
 *        "move bytes from one row to another" and a failed / partially
 *        applied migration never leaves a config readable on one side
 *        and missing on the other (the original row is preserved; see
 *        Preservation below).
 *
 *      Preservation:
 *
 *        The original `tool_configurations` / `tool_user_settings` rows
 *        for these STT classes are NOT deleted. After this migration:
 *          - new code paths read from `speech_provider_configurations`
 *          - the legacy rows still exist for any consumer that still
 *            asks the legacy read paths (e.g. the pre-0084 LLM-style
 *            tool controller pages that surface them for browsing)
 *        The new persistence layer is the single source of truth going
 *        forward; future cleanup is out of scope (see CLAUDE.md "Out
 *        of scope" for the broader plan).
 *
 *      Idempotency:
 *
 *        The migration skips rows whose `provider_class + principal_id`
 *        tuple already exists in `speech_provider_configurations`, so
 *        a re-run after a partial failure produces no duplicates and
 *        never overwrites user-edited settings.
 */
return new class extends Migration {
    use Spora\Core\Database\MigrationHelpers;

    private const DB_TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('tool_configurations')
            && !$schema->hasColumn('tool_configurations', 'is_default')
        ) {
            $schema->table('tool_configurations', static function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->after('tool_name');
            });
        }

        // Driver-branched unique index on `(tool_class) WHERE is_default=true`.
        // SQLite + PostgreSQL: native partial unique index.
        // MySQL / MariaDB: no native partial index; emulate via a generated
        //     column `tool_class_when_default = CASE WHEN is_default THEN tool_class ELSE NULL END`
        //     plus a unique index on that column (NULLs are not unique
        //     under the SQL standard, so multiple `is_default=false`
        //     rows coexist without conflict).
        $driver = Capsule::connection()->getDriverName();
        if ($schema->hasTable('tool_configurations')
            && !$this->indexExists('tool_configurations', 'uq_tool_configurations_default_per_class')
        ) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                if (!$schema->hasColumn('tool_configurations', 'tool_class_when_default')) {
                    $schema->table('tool_configurations', static function (Blueprint $table): void {
                        $table->string('tool_class_when_default')->nullable()->virtualAs('CASE WHEN is_default THEN tool_class ELSE NULL END');
                    });
                }
                $schema->table('tool_configurations', static function (Blueprint $table): void {
                    $table->unique('tool_class_when_default', 'uq_tool_configurations_default_per_class');
                });
            } else {
                // SQLite + PostgreSQL: emit a native partial unique index.
                Capsule::statement(
                    'CREATE UNIQUE INDEX uq_tool_configurations_default_per_class '
                    . 'ON tool_configurations (tool_class) WHERE is_default = true',
                );
            }
        }

        if ($schema->hasTable('tool_user_settings')
            && !$schema->hasColumn('tool_user_settings', 'is_default')
        ) {
            $schema->table('tool_user_settings', static function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->after('tool_class');
            });
        }

        if ($schema->hasTable('tool_user_settings')
            && !$this->indexExists('tool_user_settings', 'idx_tool_user_settings_default')
        ) {
            $schema->table('tool_user_settings', static function (Blueprint $table): void {
                $table->index(['principal_id', 'is_default'], 'idx_tool_user_settings_default');
            });
        }

        $this->migrateLegacySpeechConfigs($schema);
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('tool_user_settings')
            && $this->indexExists('tool_user_settings', 'idx_tool_user_settings_default')
        ) {
            $schema->table('tool_user_settings', static function (Blueprint $table): void {
                $table->dropIndex('idx_tool_user_settings_default');
            });
        }

        if ($schema->hasTable('tool_user_settings')
            && $schema->hasColumn('tool_user_settings', 'is_default')
        ) {
            $schema->table('tool_user_settings', static function (Blueprint $table): void {
                $table->dropColumn('is_default');
            });
        }

        // No-op for the speech-config backfill: see class docblock.
        // The new table is the source of truth — deleting from it on
        // `down` would lose the data this migration just wrote
        // (operators downgrading would have to re-create it manually).

        $driver = Capsule::connection()->getDriverName();
        if ($schema->hasTable('tool_configurations')) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                if ($this->indexExists('tool_configurations', 'uq_tool_configurations_default_per_class')) {
                    $schema->table('tool_configurations', static function (Blueprint $table): void {
                        $table->dropUnique('uq_tool_configurations_default_per_class');
                    });
                }
                if ($schema->hasColumn('tool_configurations', 'tool_class_when_default')) {
                    $schema->table('tool_configurations', static function (Blueprint $table): void {
                        $table->dropColumn('tool_class_when_default');
                    });
                }
            } else {
                // SQLite + PostgreSQL: drop the partial unique index.
                Capsule::statement('DROP INDEX IF EXISTS uq_tool_configurations_default_per_class');
            }

            if ($this->indexExists('tool_configurations', 'idx_tool_configurations_default')) {
                $schema->table('tool_configurations', static function (Blueprint $table): void {
                    $table->dropIndex('idx_tool_configurations_default');
                });
            }
            if ($schema->hasColumn('tool_configurations', 'is_default')) {
                $schema->table('tool_configurations', static function (Blueprint $table): void {
                    $table->dropColumn('is_default');
                });
            }
        }
    }

    private function migrateLegacySpeechConfigs(\Illuminate\Database\Schema\Builder $schema): void
    {
        if (!$schema->hasTable('speech_provider_configurations')) {
            return;
        }

        // Build a "registry" with only the core-shipped STT class —
        // plugin contributions aren't visible to the migration's CLI
        // boot. Hard-coding here is intentional (see class docblock).
        // The migration only needs the registry's `all()` accessor,
        // which returns the providers regardless of what other
        // collaborators the constructor receives. The LLM-parity
        // reshape reshaped the constructor signature (ToolConfigService
        // dropped, PrincipalService kept) — see the registry's
        // `__construct` for the current shape; we pass a fresh
        // PrincipalService to satisfy the type system without
        // bootstrapping the DI container.
        $registry = new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(
                new \Symfony\Component\HttpClient\MockHttpClient(),
                new \Spora\Services\ToolConfigService(
                    new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                    new \Psr\Log\NullLogger(),
                    [],
                ),
            )],
            new \Spora\Services\PrincipalService(new \Spora\Services\PrincipalResolver()),
        );

        $registered = $registry->all();
        $classes = array_map(static fn(SpeechToTextProviderInterface $p): string => $p::class, $registered);

        if ($classes === []) {
            return;
        }

        $this->migrateLegacyGlobalConfigs($schema, $classes);
        $this->migrateLegacyPrincipalConfigs($schema, $classes);
    }

    /**
     * @param list<string> $classes
     */
    private function migrateLegacyGlobalConfigs(\Illuminate\Database\Schema\Builder $schema, array $classes): void
    {
        if (!$schema->hasTable('tool_configurations')) {
            return;
        }

        // Migration 0083's earlier step adds `is_default`; older DBs
        // (re-running on a very old schema) would error on the where
        // clause — gate the read on column existence so the migration
        // is also safe on un-upgraded databases (it just won't
        // preserve is_default for them).
        $hasIsDefault = $schema->hasColumn('tool_configurations', 'is_default');

        $rows = Capsule::table('tool_configurations')
            ->whereIn('tool_class', $classes)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $existing = Capsule::table('speech_provider_configurations')
                ->where('provider_class', $row->tool_class)
                ->whereNull('principal_id')
                ->where('is_global', true)
                ->exists();
            if ($existing) {
                continue;
            }

            Capsule::table('speech_provider_configurations')->insert([
                'principal_id'   => null,
                'provider_class' => $row->tool_class,
                'display_name'   => (string) ($row->tool_name ?? $row->tool_class),
                'settings'       => $row->settings,
                'is_default'     => $hasIsDefault ? (bool) $row->is_default : false,
                'is_global'      => true,
                'created_at'     => $this->formatDateTime($row->created_at ?? null),
                'updated_at'     => $this->formatDateTime($row->updated_at ?? null),
            ]);
        }
    }

    /**
     * @param list<string> $classes
     */
    private function migrateLegacyPrincipalConfigs(\Illuminate\Database\Schema\Builder $schema, array $classes): void
    {
        if (!$schema->hasTable('tool_user_settings')) {
            return;
        }

        $hasIsDefault = $schema->hasColumn('tool_user_settings', 'is_default');

        $rows = Capsule::table('tool_user_settings')
            ->whereIn('tool_class', $classes)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if (!isset($row->principal_id)) {
                continue;
            }

            // Skip rows pointing at a principal that has been deleted
            // (or never materialised). The FK on
            // speech_provider_configurations.principal_id would
            // otherwise fail; the original legacy row is left alone in
            // case the operator wants to repair the principal
            // manually.
            $principalExists = Capsule::table('principals')
                ->where('id', $row->principal_id)
                ->exists();
            if (!$principalExists) {
                continue;
            }

            $existing = Capsule::table('speech_provider_configurations')
                ->where('provider_class', $row->tool_class)
                ->where('principal_id', $row->principal_id)
                ->exists();
            if ($existing) {
                continue;
            }

            Capsule::table('speech_provider_configurations')->insert([
                'principal_id'   => $row->principal_id,
                'provider_class' => $row->tool_class,
                'display_name'   => (string) $row->tool_class,
                'settings'       => $row->settings,
                'is_default'     => $hasIsDefault ? (bool) $row->is_default : false,
                'is_global'      => false,
                'created_at'     => $this->formatDateTime($row->created_at ?? null),
                'updated_at'     => $this->formatDateTime($row->updated_at ?? null),
            ]);
        }
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return date(self::DB_TIMESTAMP_FORMAT);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::DB_TIMESTAMP_FORMAT);
        }
        $ts = strtotime((string) $value);
        return $ts !== false ? gmdate(self::DB_TIMESTAMP_FORMAT, $ts) : date(self::DB_TIMESTAMP_FORMAT);
    }
};
