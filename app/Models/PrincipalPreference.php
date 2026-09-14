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
 * The `preferred_speech_config_id` is the FK to
 * `speech_provider_configurations(id)` introduced in migration 0088
 * (replacing the `preferred_speech_provider_class` string column from
 * migration 0084). Migration 0084 stored a class FQCN because speech
 * configs spanned two tables; migration 0085 unified them so the FK is
 * the natural shape.
 *
 * @property int $id
 * @property int $principal_id
 * @property int|null $preferred_llm_config_id
 * @property int|null $preferred_speech_config_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class PrincipalPreference extends Model
{
    protected $table = 'principal_preferences';

    /** @var list<string> */
    protected $fillable = ['principal_id', 'preferred_llm_config_id', 'preferred_speech_config_id'];

    public $timestamps = true;

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    public function preferredLlmConfig(): BelongsTo
    {
        return $this->belongsTo(LLMDriverConfiguration::class, 'preferred_llm_config_id');
    }

    public function preferredSpeechConfig(): BelongsTo
    {
        return $this->belongsTo(SpeechProviderConfiguration::class, 'preferred_speech_config_id');
    }
}
