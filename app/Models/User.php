<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int         $id
 * @property string     $email
 * @property string     $username
 * @property int        $status
 * @property int        $verified
 * @property int        $registered
 * @property string|null $password
 * @property string|null $name
 * @property \Illuminate\Support\Carbon|null $date_of_birth
 * @property string|null $about_me
 * @property float|null  $height_cm
 * @property float|null  $weight_kg
 * @method static User|null find(mixed $id, array $columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder where(string $column, mixed $operator = null, mixed $value = null)
 */
final class User extends Model
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
        'name',
        'date_of_birth',
        'about_me',
        'height_cm',
        'weight_kg',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'date_of_birth' => 'date',
        'height_cm'     => 'float',
        'weight_kg'     => 'float',
    ];

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(UserLocation::class);
    }
}
