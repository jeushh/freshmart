<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'admin_users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password_hash'];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        $granted = $this->role?->permissions ?? [];

        return in_array('*', $granted, true)
            || count(array_intersect($permissions, $granted)) > 0;
    }
}
