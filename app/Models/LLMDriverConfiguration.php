<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int|null $principal_id   null only for global configs (the unique global row)
 * @property string $name
 * @property string $driver_class
 * @property string|null $settings  (encrypted JSON)
 * @property int|null $context_window
 * @property int|null $max_tokens_output
 * @property bool $is_default
 * @property bool $is_global
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class LLMDriverConfiguration extends Model
{
    protected $table = 'llm_driver_configurations';

    protected $fillable = [
        'principal_id',
        'name',
        'driver_class',
        'settings',
        'context_window',
        'max_tokens_output',
        'is_default',
        'is_global',
    ];

    public function principal(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<PrincipalPreference, $this>
     */
    public function principalPreferences(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrincipalPreference::class, 'preferred_llm_config_id');
    }

    protected $casts = [
        'is_default' => 'boolean',
        'is_global' => 'boolean',
        'principal_id' => 'integer',
        'context_window' => 'integer',
        'max_tokens_output' => 'integer',
    ];

    protected static function booted(): void
    {
        // Intentionally empty. The XOR invariant check (a config is either
        // principal-scoped XOR global) lives in {@see self::save()} so the
        // rule fires on every persistence path. Spora uses Illuminate\
        // Database Capsule standalone, so the Event Dispatcher is not
        // wired into the static `Model::$dispatcher` and `static::saving`
        // listeners silently never fire; an override here is the only
        // path that always runs.
    }

    /**
     * Validate the XOR invariant before letting Eloquent persist the
     * row.
     *
     * @param  array<string, mixed> $options
     */
    public function save(array $options = []): bool
    {
        $this->validateGlobalXor();
        return parent::save($options);
    }

    /**
     * XOR check: a config either belongs to a principal (`principal_id` set,
     * `is_global = false`) or is the global shared config (`principal_id`
     * null, `is_global = true`). Enforced at the model layer because
     * MySQL 5.7 parses but ignores CHECK, and SQLite CHECK enforcement
     * is engine-version-dependent.
     *
     * @throws LogicException
     */
    public function validateGlobalXor(): void
    {
        $hasPrincipal = $this->principal_id !== null;
        $isGlobal     = (bool) $this->is_global;
        if ($hasPrincipal === $isGlobal) {
            throw new LogicException(
                'LLMDriverConfiguration must be either principal-scoped (principal_id set, is_global=false) '
                . 'or global (principal_id=null, is_global=true).',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $value = $this->attributes['settings'] ?? null;
        return $value !== null ? (json_decode($value, true) ?? []) : [];
    }
}
