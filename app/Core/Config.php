<?php

declare(strict_types=1);

namespace Spora\Core;

use Closure;
use Spora\Core\Exceptions\SettingsDecryptionException;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;

/**
 * Resolved configuration, keyed by the same `SPORA_*` names used in `.env`,
 * `config.php` and the admin API.
 *
 * Built from three layers at container time — defaults, `config.php`, `.env` —
 * and given a fourth, the database, by {@see self::applySettingsOverlay()}.
 * The plugin-contributed settings arrive in a fifth, deferred layer: see
 * {@see self::deferLayer()}.
 * Re-applying it is idempotent and is how a write made inside a request
 * becomes visible to the response answering it
 * ({@see \Spora\Http\MailConfigController::mailConfigResponse()}). There is no `all()` accessor that reads
 * raw env: a value either went through a declared {@see \Spora\Settings\SettingDefinition}
 * or it is not configuration.
 */
final class Config
{
    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_CONFIG_FILE = 'config.php';
    public const SOURCE_ENV = 'env';
    public const SOURCE_DATABASE = 'database';

    /** @var list<string> */
    private array $undecryptable = [];

    /**
     * A layer of values that is resolved on the first read.
     *
     * @var (Closure(): array{values: array<string, mixed>, sources: array<string, string>})|null
     */
    private ?Closure $pendingLayer = null;

    /**
     * @param array<string, mixed>  $values  Keyed by `SPORA_*`.
     * @param array<string, string> $sources Keyed by `SPORA_*`; one of the `SOURCE_*` constants.
     */
    public function __construct(
        private array $values = [],
        private array $sources = [],
    ) {}

    /**
     * Defer a layer of values until the config is read.
     *
     * The plugin-contributed settings cannot be part of the construction: a
     * plugin's settings provider may depend on the config, and the container
     * refuses that cycle. The layer is therefore resolved on the first read,
     * when the config exists and a provider can ask for it.
     *
     * @param Closure(): array{values: array<string, mixed>, sources: array<string, string>} $layer
     */
    public function deferLayer(Closure $layer): void
    {
        $this->pendingLayer = $layer;
    }

    /**
     * Layer the stored settings over the three boot layers.
     *
     * This is a separate call rather than constructor work because of a real
     * dependency cycle: `Kernel::__construct()` reads `SPORA_APP_ENV` to set
     * up error handling, and `Database` needs `SPORA_DB_*` to open the
     * connection these rows live in. Neither can happen before `Kernel::handle()`
     * calls `Database::boot()`, so anything that reads config before then
     * necessarily sees only the boot layers.
     *
     * Applied immediately after the database boots. A setting marked
     * `bootOnly` is skipped: it was read before this ran, so a stored value
     * would arrive too late to have any effect and would only mislead.
     *
     * Decryption failures are absorbed one layer down, in
     * {@see SettingsRepository::allForGroup()} — that is where the catch is,
     * and moving it here would make a lost key fatal on every request.
     * {@see self::get()} is where it resurfaces.
     */
    public function applySettingsOverlay(SettingsRepository $repository, SettingsRegistry $registry): void
    {
        $byGroup = [];
        foreach ($registry->all() as $definition) {
            if ($definition->bootOnly) {
                continue;
            }
            $byGroup[$definition->group][] = $definition;
        }

        // Iterating the declarations rather than the stored rows is what
        // enforces `bootOnly`: the repository returns every row in a group,
        // including the boot-only keys, and a stored `SPORA_DB_HOST` would
        // otherwise overwrite the value the database was opened with.
        foreach ($byGroup as $group => $definitions) {
            $rows = $repository->allForGroup((string) $group);
            foreach ($definitions as $definition) {
                $row = $rows[$definition->key] ?? null;
                if ($row === null) {
                    continue;
                }
                if ($row->undecryptable) {
                    $this->undecryptable[] = $definition->key;
                    continue;
                }
                $this->values[$definition->key] = $row->value;
                $this->sources[$definition->key] = self::SOURCE_DATABASE;
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->applyPendingLayer();
        $this->assertReadable($key);

        return $this->values[$key] ?? $default;
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key);

        // A list-valued key read as a string is a caller mistake, not a value
        // to stringify — `(string) ['png']` is `"Array"` with a notice.
        if ($value === null || is_array($value) || is_bool($value)) {
            return $default;
        }

        return (string) $value;
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);
        if ($value === null || is_array($value) || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null || is_array($value)) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function has(string $key): bool
    {
        $this->applyPendingLayer();

        return array_key_exists($key, $this->values);
    }

    public function sourceOf(string $key): string
    {
        $this->applyPendingLayer();

        return $this->sources[$key] ?? self::SOURCE_DEFAULT;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->applyPendingLayer();

        return $this->values;
    }

    /**
     * Keys whose stored value exists but cannot be decrypted with the current
     * secret key. Surfaced by the admin API so an operator can see which
     * credentials need re-entering.
     *
     * @return list<string>
     */
    public function undecryptableKeys(): array
    {
        return $this->undecryptable;
    }

    /**
     * A stored value we cannot read is reported where it is actually used,
     * not swallowed at boot.
     *
     * The overlay skips an unreadable row, so the boot-layer value stands —
     * the registry default when nothing else sets it — and the application
     * starts. A site that will not boot cannot show the admin the error
     * explaining why. But a consumer reaching for a credential it cannot read must fail
     * visibly: silently proceeding with an empty key turns "your secret key
     * was rotated" into an opaque 401 from a third-party API, hours later,
     * with nothing pointing back here.
     */
    private function assertReadable(string $key): void
    {
        if (in_array($key, $this->undecryptable, true)) {
            throw new SettingsDecryptionException(sprintf(
                'Setting "%s" is stored but cannot be decrypted with the current SPORA_SECRET_KEY. '
                . 'Re-enter its value in Global Settings, or run `bin/spora settings:reset` to fall back to your defaults.',
                $key,
            ));
        }
    }

    /**
     * Fold the deferred layer in, once.
     *
     * The layer is cleared before it runs: a provider that reads the config
     * while the layer is being resolved must not start it again.
     *
     * A value that is already there wins. The database layer is applied over
     * the boot layers and can be applied before this one — a stored value
     * must not be replaced by the declared default.
     */
    private function applyPendingLayer(): void
    {
        $layer = $this->pendingLayer;
        if ($layer === null) {
            return;
        }
        $this->pendingLayer = null;

        ['values' => $values, 'sources' => $sources] = $layer();

        foreach ($values as $key => $value) {
            if (array_key_exists($key, $this->values)) {
                continue;
            }
            $this->values[$key] = $value;
            $this->sources[$key] = $sources[$key] ?? self::SOURCE_DEFAULT;
        }
    }
}
