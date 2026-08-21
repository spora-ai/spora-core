<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per principal-scoped LLM preference. Was `user_preferences` until
 * the principals-and-groups migration (0067) renamed the table to
 * `principal_preferences` and re-keyed the FK from `users` to `principals`.
 *
 * The `preferred_llm_config_id` may point at a principal-owned config or
 * a global config (whose `principal_id IS NULL`); validation in
 * {@see \Spora\Services\LLMConfigPersistence} rejects cross-principal
 * pointers and the {@see \Spora\Services\LlmConfigValidator} gates who can
 * write the field for which principal.
 *
 * @property int $id
 * @property int $principal_id
 * @property int|null $preferred_llm_config_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class PrincipalPreference extends Model
{
    protected $table = 'principal_preferences';

    /** @var list<string> */
    protected $fillable = ['principal_id', 'preferred_llm_config_id'];

    public $timestamps = true;

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    public function preferredLlmConfig(): BelongsTo
    {
        return $this->belongsTo(LLMDriverConfiguration::class, 'preferred_llm_config_id');
    }
}
