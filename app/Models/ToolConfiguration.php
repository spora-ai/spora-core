<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int    $id
 * @property string $tool_class
 * @property string $tool_name
 * @property string $settings  (encrypted JSON; never access directly)
 * @property string|null $created_at
 * @property string|null $updated_at
 * @method   static \Illuminate\Database\Eloquent\Builder where(string $column, mixed $operator = null, mixed $value = null)
 */
final class ToolConfiguration extends Model
{
    /** @var string */
    protected $table = 'tool_configurations';

    /** @var list<string> */
    protected $fillable = [
        'tool_class',
        'tool_name',
        'settings',
    ];

    // settings is intentionally NOT in $casts — all access via ToolConfigService
    // Do NOT add 'settings' => 'array' here.

    /**
     * Guard against direct access to the encrypted settings column.
     * All reads/writes must go through ToolConfigService.
     *
     * @throws LogicException
     */
    public function getSettingsAttribute(): never
    {
        throw new LogicException('Access tool settings via ToolConfigService, not directly.');
    }
}
