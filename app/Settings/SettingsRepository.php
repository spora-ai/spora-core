<?php

declare(strict_types=1);

namespace Spora\Settings;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Throwable;

/**
 * The database layer of {@see \Spora\Core\Config}.
 *
 * No caching, deliberately. A row read per request is fresh in both tiers —
 * each web request builds a fresh kernel, and the worker spawns a fresh
 * process per task — and `ToolConfigService` already sets that precedent.
 * A memoizing decorator here would buy nothing and would make "settings apply
 * on the next request" untrue.
 */
final class SettingsRepository
{
    private ?bool $tableExists = null;

    public function __construct(
        private readonly SecurityManagerInterface $security,
        private readonly SettingsRegistry $registry,
    ) {}

    /**
     * @return array<string, SettingsRow> keyed by setting key
     */
    public function allForGroup(string $group): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $rows = Capsule::table('settings')->where('group_key', $group)->get();
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row->setting_key;
            $definition = $this->registry->get($key);
            if ($definition === null) {
                // A key that is not declared is not a setting. It may be left
                // over from a plugin that has since been uninstalled.
                continue;
            }

            $out[$key] = $this->decodeRow(
                group: $group,
                key: $key,
                stored: (string) $row->value,
                encrypted: (bool) $row->encrypted,
                updatedBy: $row->updated_by === null ? null : (int) $row->updated_by,
                definition: $definition,
            );
        }

        return $out;
    }

    /**
     * @param mixed $value Null deletes the row, which is how "not set" is
     *                     stored. An empty string is a value — it is how a
     *                     secret gets cleared.
     */
    public function put(SettingDefinition $definition, mixed $value, ?int $userId): void
    {
        if ($value === null) {
            $this->delete($definition->group, $definition->key);

            return;
        }

        // Coerce before storing. `encode()` only formats, so without this a
        // caller could persist a value the registry would have rejected — an
        // `svg` in the image allowlist being the one that matters. A stored
        // value has to be the same value a read would produce.
        $encoded = $this->registry->encode($definition, $this->registry->coerce($definition, $value));
        $isSecret = $definition->secret;

        // An empty secret is unencodable, so it must never reach a row.
        // `encrypt('')` emits a bare nonce+MAC (40 bytes) and `decrypt()`
        // rejects anything under nonce+MAC+1 (41) as too short — the row would
        // read as undecryptable forever, and every read of that key would then
        // throw. "No credential" and "clear the credential" mean the same
        // thing to the operator, so both land on the same path as `null`.
        //
        // Checked on the encoded value rather than the input, because a
        // transform can normalise a non-empty input down to `''`.
        //
        // Scoped to secrets on purpose: `''` is a legitimate value for a text
        // or list setting, and storing it must keep working.
        if ($isSecret && $encoded === '') {
            $this->delete($definition->group, $definition->key);

            return;
        }

        $payload = [
            'value'      => $isSecret ? $this->security->encrypt($encoded)->toStorageString() : $encoded,
            'encrypted'  => $isSecret,
            'updated_by' => $userId,
            'updated_at' => Carbon::now(),
        ];

        Capsule::table('settings')->updateOrInsert(
            ['group_key' => $definition->group, 'setting_key' => $definition->key],
            $payload,
        );
    }

    public function delete(string $group, string $key): void
    {
        if (!$this->tableExists()) {
            return;
        }
        Capsule::table('settings')
            ->where('group_key', $group)
            ->where('setting_key', $key)
            ->delete();
    }

    private function decodeRow(
        string $group,
        string $key,
        string $stored,
        bool $encrypted,
        ?int $updatedBy,
        SettingDefinition $definition,
    ): SettingsRow {
        if (!$encrypted) {
            return new SettingsRow(
                group: $group,
                key: $key,
                value: $this->registry->decode($definition, $stored),
                encrypted: false,
                updatedBy: $updatedBy,
            );
        }

        try {
            $plain = $this->security->decrypt(new EncryptedValue($stored));
        } catch (Throwable) {
            // A rotated or lost SPORA_SECRET_KEY makes every secret unreadable.
            // This row is reported as undecryptable rather than dropped: the
            // value still exists on disk and becomes readable again if the
            // right key comes back. See Config::applySettingsOverlay().
            error_log(sprintf(
                '[Spora] settings: cannot decrypt "%s.%s" with the current secret key; keeping the value from config.php/.env, or the default when neither sets it.',
                $group,
                $key,
            ));

            return new SettingsRow($group, $key, null, true, $updatedBy, undecryptable: true);
        }

        return new SettingsRow(
            group: $group,
            key: $key,
            value: $this->registry->decode($definition, $plain),
            encrypted: true,
            updatedBy: $updatedBy,
        );
    }

    /**
     * A fresh clone has no migrated schema and `bin/spora db:reset` drops the
     * table, so this can legitimately be false on a running install. Returning
     * `[]` rather than letting a `QueryException` escape is what keeps Spora
     * bootable in that state — the alternative is a site that will not start,
     * which is exactly the failure the settings table exists to prevent.
     */
    private function tableExists(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        try {
            return $this->tableExists = Capsule::schema()->hasTable('settings');
        } catch (QueryException) {
            return $this->tableExists = false;
        }
    }
}
