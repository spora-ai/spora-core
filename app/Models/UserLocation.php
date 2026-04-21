<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string      $name
 * @property string $address
 * @property bool   $is_default
 */
final class UserLocation extends Model
{
    protected $table = 'user_locations';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'address',
        'is_default',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
