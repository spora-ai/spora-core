<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $preferred_llm_config_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class UserPreference extends Model
{
    protected $table = 'user_preferences';

    protected $fillable = ['user_id', 'preferred_llm_config_id'];

    public $timestamps = true;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preferredLlmConfig(): BelongsTo
    {
        return $this->belongsTo(LLMDriverConfiguration::class, 'preferred_llm_config_id');
    }
}
