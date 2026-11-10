<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved location, owned by the principal it belongs to.
 *
 * @property int         $id
 * @property int         $principal_id
 * @property string      $name
 * @property string      $address
 * @property bool        $is_default
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class PrincipalLocation extends Model
{
    protected $table = 'principal_locations';

    /** @var list<string> */
    protected $fillable = [
        'principal_id',
        'name',
        'address',
        'is_default',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }
}
