<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

// `LogicException` is kept for the XOR invariant below; removed
// accessor import would change the public API surface.

/**
 * One row per speech-to-text provider configuration.
 *
 * Mirrors {@see LLMDriverConfiguration} field-for-field except the
 * `driver_class` column is renamed to `provider_class` (speech provider
 * classes are not "drivers" in Spora's lexicon — the LLM package owns
 * that term). Same XOR invariant (a row is principal-scoped XOR global)
 * is enforced at the model layer rather than relying on the database
 * engine because both MySQL 5.7 and SQLite (engine-version dependent)
 * silently ignore `CHECK` clauses.
 *
 * Settings encryption mirrors LLM: the column is opaque encrypted
 * JSON. Callers must go through {@see \Spora\Services\SpeechProviderConfigPersistence}
 * to read or write it — the persistence layer owns the crypto round-trip
 * via `getRawOriginal('settings')` (read) and `encodeSettingsString()`
 * (write). Direct `$config->settings` returns the raw encrypted blob
 * with no decoding — by design, to discourage callers from skipping
 * the persistence layer.
 *
 * @property int $id
 * @property int|null $principal_id   null only for global configs (`is_global = true`).
 * @property string $display_name
 * @property string $provider_class
 * @property string|null $settings    (encrypted JSON)
 * @property bool $is_default
 * @property bool $is_global
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class SpeechProviderConfiguration extends Model
{
    protected $table = 'speech_provider_configurations';

    /** @var list<string> */
    protected $fillable = [
        'principal_id',
        'provider_class',
        'display_name',
        'settings',
        'is_default',
        'is_global',
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /** @var array<string, string> */
    protected $casts = [
        'is_default' => 'boolean',
        'is_global' => 'boolean',
        'principal_id' => 'integer',
    ];

    // `settings` is intentionally NOT in $casts — all access via
    // the speech persistence layer that owns the encryption.
    // Do NOT add 'settings' => 'array' here. Direct reads of
    // `$config->settings` go through `getRawOriginal('settings')` in
    // the persistence layer; writes happen via the persistence
    // layer's `encodeSettingsString()` so the encrypted JSON layout
    // is consistent with the rest of the app.

    /**
     * XOR invariant: a row is either principal-scoped
     * (`principal_id` set, `is_global = false`) or global
     * (`principal_id` null, `is_global = true`). Enforced on every
     * save so partial states can't reach the database — MySQL 5.7
     * silently ignores CHECK clauses, and SQLite enforcement is
     * engine-version dependent.
     *
     * @throws LogicException
     */
    public function validateGlobalXor(): void
    {
        $hasPrincipal = $this->principal_id !== null;
        $isGlobal = (bool) $this->is_global;
        if ($hasPrincipal === $isGlobal) {
            throw new LogicException(
                'SpeechProviderConfiguration must be either principal-scoped (principal_id set, is_global=false) '
                . 'or global (principal_id=null, is_global=true).',
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function save(array $options = []): bool
    {
        $this->validateGlobalXor();
        return parent::save($options);
    }
}
