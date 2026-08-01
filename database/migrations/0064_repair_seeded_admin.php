<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('users')) {
            return;
        }

        $user = Capsule::table('users')
            ->where('email', 'admin@spora.local')
            ->first(['id', 'roles_mask']);

        if ($user === null) {
            return;
        }

        Capsule::table('users')
            ->where('id', $user->id)
            ->update([
                'roles_mask' => (int) $user->roles_mask | Role::ADMIN,
                'verified'   => 1,
                'status'     => 1,
            ]);
    }
};
