<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * `users.locale` — the language one person reads Spora in.
 *
 * Nullable, and null means "auto": {@see \Spora\I18n\LocaleResolver} then
 * falls through to the instance default (`SPORA_DEFAULT_LANG`) and finally
 * to English, so every existing account renders exactly as it did before
 * this column existed and no backfill is needed.
 *
 * Why `users` and not `principal_preferences`: locale is a property of the
 * person, not of the context they happen to be acting in. A user belongs to
 * a user-principal *and* any number of group principals, so on a
 * principal-scoped column the language would silently change with the
 * active principal — the same account, two languages, chosen by which
 * group was open.
 *
 * `varchar(10)` rather than a two-character code: the column stores a
 * catalogue filename, not an ISO language, and a script or region variant
 * (`pt-BR`) would not fit the short form.
 *
 * Idempotency: gated on `hasColumn()`, so a re-run after a partial failure
 * is a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('users') || $schema->hasColumn('users', 'locale')) {
            return;
        }

        $schema->table('users', static function (Blueprint $table): void {
            $table->string('locale', 10)->nullable()->after('weight_kg');
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('users') || !$schema->hasColumn('users', 'locale')) {
            return;
        }

        $schema->table('users', static function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};