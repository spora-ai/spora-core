<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int    $id
 * @property string $email
 * @property string $username
 * @property int    $status
 * @property int    $verified
 * @property int    $registered
 * @property string|null $password
 * @method static User|null find(mixed $id, array $columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder where(string $column, mixed $operator = null, mixed $value = null)
 */
class User extends Model
{
    /** @var string */
    protected $table = 'users';

    /** @var list<string> */
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

    /** @var list<string> */
    protected $hidden = [
        'password',
    ];

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }
}
