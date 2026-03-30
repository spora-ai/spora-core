<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Model
{
    protected $table = 'users';

    protected $fillable = [
        'email',
        'password',
        'username',
        'status',
        'verified',
        'resettable',
        'roles_mask',
        'registered',
        'last_login',
        'force_logout',
    ];

    protected $hidden = [
        'password',
    ];

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }
}
