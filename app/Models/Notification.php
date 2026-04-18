<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string      $type
 * @property string      $title
 * @property string|null $body
 * @property array|null  $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
final class Notification extends Model
{
    protected $table = 'notifications';

    /** @var bool Eloquent timestamps disabled — created_at managed by the creating hook. */
    public $timestamps = false;

    /** Set created_at on creation if not already provided. */
    protected static function booted(): void
    {
        static::creating(function (Notification $notif): void {
            if ($notif->created_at === null) {
                $notif->created_at = Carbon::now();
            }
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'data'      => 'array',
        'read_at'   => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
