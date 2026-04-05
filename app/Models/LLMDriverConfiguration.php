<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $driver_class
 * @property string|null $settings  (encrypted JSON)
 * @property bool $is_default
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class LLMDriverConfiguration extends Model
{
    protected $table = 'llm_driver_configurations';

    protected $fillable = [
        'user_id',
        'name',
        'driver_class',
        'settings',
        'is_default',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $value = $this->attributes['settings'] ?? null;
        return $value !== null ? (json_decode($value, true) ?? []) : [];
    }
}
