<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $agent_id
 * @property string $key
 * @property string|null $value
 */
class AgentMemory extends Model
{
    protected $table = 'agent_memory';

    protected $fillable = [
        'agent_id',
        'key',
        'value',
    ];
}
