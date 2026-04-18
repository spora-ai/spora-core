<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $agent_id
 * @property string $name
 * @property string|null $description
 * @property string $prompt_template
 * @property array|null $variables
 * @property int|null $max_steps
 * @property bool $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class AgentPromptTemplate extends Model
{
    protected $table = 'agent_prompt_templates';

    protected $fillable = [
        'agent_id',
        'name',
        'description',
        'prompt_template',
        'variables',
        'max_steps',
        'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
        'max_steps' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
