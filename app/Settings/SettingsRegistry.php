<?php

declare(strict_types=1);

namespace Spora\Settings;

use Spora\Core\Paths;
use Spora\Core\RequestOrigin;

/**
 * Every operator-facing setting, declared once, in one place.
 *
 * This replaces the `$defaults` array and the 36 `collectEnvOverrides()`
 * `$apply()` calls in `ContainerDefinitions`. Nothing checked that those two
 * agreed with each other, which is how drift survived:
 * a key added to `$defaults` with no `$apply()` line is silently
 * un-overridable by env, and a `$apply()` line with no `$defaults` entry
 * resolves only when the env var happens to be set.
 *
 * Resolution order, last wins:
 *
 *     registry defaults  <  config.php  <  .env / OS environment  <  DB
 *
 * The DB layer is absent at boot for the settings marked `bootOnly`, because
 * they are read before the database can be opened. That is a chicken-and-egg
 * guard, not an oversight — see {@see \Spora\Core\Config::applySettingsOverlay()}.
 *
 * Env vars that only redirect `Paths` (`SPORA_APP_DIR`, `SPORA_STORAGE_DIR`,
 * `SPORA_PLUGINS_DIR`, `SPORA_RECIPES_DIR`, `SPORA_CONFIG_PATH`,
 * `SPORA_ENV_FILE`) are deliberately NOT declared. They never entered the
 * config array, they are consumed while `Paths` itself is being built, and
 * showing an operator an editable-looking field for one would be a lie.
 *
 * A plugin's own settings join the same set through
 * {@see PluginSettingsRegistry}: the registry is still the one authority on
 * what a setting is, so a plugin-contributed key is accepted by the admin
 * API, stored under the plugin's own group and resolved like any other.
 */
final class SettingsRegistry
{
    /**
     * Section key => operator-facing label, in display order.
     *
     * The UI groups by this, not by key shape, so flattening the nested
     * config keys cost the operator nothing.
     *
     * @var array<string, string>
     */
    public const SECTION_LABELS = [
        'general'   => 'General',
        'access'    => 'Access',
        'timeouts'  => 'Timeouts',
        'worker'    => 'Worker',
        'plugins'   => 'Plugins',
        'media'     => 'Media',
        'mail'      => 'Mail',
        'database'  => 'Database',
        'realtime' => 'Realtime',
    ];

    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    /** @var array<string, SettingDefinition>|null */
    private ?array $merged = null;

    private readonly SettingDeclarations $declarations;

    private readonly ?PluginSettingsRegistry $pluginSettings;

    public function __construct(Paths $paths, ?PluginSettingsRegistry $pluginSettings = null)
    {
        $this->declarations = new SettingDeclarations($paths);
        $this->pluginSettings = $pluginSettings;
    }

    /**
     * @return array<string, SettingDefinition> keyed by setting key
     */
    public function all(): array
    {
        if ($this->merged !== null) {
            return $this->merged;
        }

        $core = $this->definitions ??= $this->declarations->build();
        $plugin = $this->pluginSettings?->definitions() ?? [];

        return $this->merged = $plugin === [] ? $core : array_merge($core, $plugin);
    }

    /**
     * @return array<string, string>
     */
    public function sectionLabels(): array
    {
        return self::SECTION_LABELS;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?SettingDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return list<SettingDefinition>
     */
    public function forSection(string $section): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(SettingDefinition $d): bool => $d->section === $section,
        ));
    }

    /**
     * Keys that must never come from the database, because they are read
     * before it is open — or they are filesystem facts.
     *
     * @return list<string>
     */
    public function bootOnlyKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            static fn(SettingDefinition $d): bool => $d->bootOnly,
        ));
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            static fn(SettingDefinition $d): bool => $d->secret,
        ));
    }

    /**
     * Normalise a raw value into this setting's declared type.
     *
     * `config.php` and `.env` come through here. The database is read by
     * {@see self::decode()}, which normalises list and toggle forms but applies
     * no `transform` — so the two can disagree about a key that has one. A row
     * written through {@see \Spora\Settings\SettingsRepository::put()} is
     * canonical already, because that path coerces before it encodes; one
     * inserted by any other means comes back un-normalised.
     */
    public function coerce(SettingDefinition $d, mixed $raw): mixed
    {
        if ($raw === null) {
            return $d->default;
        }
        if (in_array($d->type, [SettingDefinition::TYPE_MULTI_SELECT, SettingDefinition::TYPE_LIST], true)) {
            return $this->normalizeList($d, $raw);
        }

        return match ($d->transform) {
            // Normalised, not validated: the router strips the prefix from
            // incoming paths, so `/spora` and `spora` must resolve the same.
            'app_prefix' => RequestOrigin::normalizePrefix((string) $raw),
            // `php_mail` was the long-standing fallback and resolves to
            // `sendmail://default` — the same DSN as `sendmail`. Canonicalising
            // it here means an operator's stored value and the registry
            // default are one name, which is what lets the admin page offer a
            // closed set of drivers instead of accepting whatever was typed.
            'mail_driver' => match (strtolower(trim((string) $raw))) {
                'php_mail' => 'sendmail',
                default => strtolower(trim((string) $raw)),
            },
            default => match ($d->type) {
                SettingDefinition::TYPE_TOGGLE => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                SettingDefinition::TYPE_NUMBER => (int) $raw,
                default => (string) $raw,
            },
        };
    }

    /**
     * Render a value for storage. Lists go to JSON so they round-trip exactly;
     * the comma-separated form is an input convenience for operators typing into
     * `.env`, not a storage format.
     *
     * Takes no definition because nothing about it needs one — shape follows the
     * value's PHP type, and the coercion to that type happened in
     * {@see self::coerce()}.
     */
    public function encode(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Read a stored value back into this setting's declared type: a list
     * comes back as JSON, normalised into a list of strings; a toggle becomes
     * a boolean; a number an integer; any other type the stored string.
     *
     * Unlike {@see self::coerce()} this applies no `transform`: a row written
     * through {@see \Spora\Settings\SettingsRepository::put()} is canonical
     * already, one inserted by any other means comes back without it.
     */
    public function decode(SettingDefinition $d, string $raw): mixed
    {
        if (in_array($d->type, [SettingDefinition::TYPE_MULTI_SELECT, SettingDefinition::TYPE_LIST], true)) {
            return $this->normalizeList($d, $this->maybeJsonDecode($raw));
        }

        return match ($d->type) {
            SettingDefinition::TYPE_TOGGLE => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            SettingDefinition::TYPE_NUMBER => (int) $raw,
            default => $raw,
        };
    }

    /**
     * Split a delimited string, or flatten an array, into a list — applying
     * the setting's transform either way.
     *
     * Every input form has to go through here. A list arrives as comma- or
     * whitespace-separated text from `.env`, as a textarea's raw text from
     * the admin page, as JSON from the database, and as a real array from a
     * JSON request body. Filtering only the text form would let the others
     * past a rejection rule.
     *
     * @return list<string>
     */
    private function normalizeList(SettingDefinition $d, mixed $raw): array
    {
        if (is_array($raw)) {
            $raw = implode(',', array_map(static fn(mixed $v): string => (string) $v, $raw));
        }

        return $d->transform === 'image_types'
            ? self::coerceImageTypes((string) $raw)
            : self::tokenize((string) $raw);
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            static fn(string $p): bool => $p !== '',
        )));
    }

    /**
     * Lists are stored as JSON, but a value can also arrive as delimited text
     * from `.env` or the admin page. Only a genuine JSON array is unwrapped —
     * a bare token like `png` is not valid JSON and must stay a string.
     */
    private function maybeJsonDecode(string $raw): mixed
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $raw;
    }

    /**
     * Parse an operator-supplied image-type list.
     *
     * Whitespace is trimmed; tokens are lowercased; `jpg`/`jpeg` collapse to
     * `jpeg`; order and duplicates collapse to the first occurrence.
     *
     * SVG is rejected. This is a security control, not a formatting rule:
     * the result is the allowlist behind `MediaAllowedTypesService`, which
     * `MediaDerivativeService` calls the deliberate surface for images. An
     * unrendered SVG served from that allowlist is script execution.
     *
     * @return list<string>
     */
    private static function coerceImageTypes(string $raw): array
    {
        $tokens = preg_split('/[\s,]+/', trim($raw));
        if ($tokens === false) {
            return [];
        }
        $normalized = [];
        foreach ($tokens as $token) {
            // A token can arrive wrapped in JSON or quoting — the admin UI
            // renders a `list` setting as a textarea, and a list that came
            // back through one is re-tokenised from whatever the operator
            // left behind. `trim()` strips whitespace, not the quote
            // characters or the brackets a JSON array carries, and a
            // `"svg"` token would otherwise sail past the check below
            // because it does not *start with* `svg`.
            //
            // This is a security control, so it must hold for the spelling
            // the UI produces as readily as for the one a person types.
            $t = strtolower(trim($token, " \t\n\r\0\x0B\"'[]"));
            $t = ltrim($t, '.');
            if ($t === '') {
                continue;
            }
            // Matched by suffix rather than by equality. `svg`, `svgz`,
            // `image/svg+xml` and a leading-dotted `.svg` are all the same
            // format, and an allowlist that filtered only the exact string
            // `svg` would filter the one spelling an operator is least likely
            // to type.
            $bare = str_contains($t, '/') ? substr($t, (int) strrpos($t, '/') + 1) : $t;
            if (str_starts_with($bare, 'svg')) {
                continue;
            }
            $normalized[] = $bare === 'jpg' ? 'jpeg' : $bare;
        }

        return array_values(array_unique($normalized));
    }
}
