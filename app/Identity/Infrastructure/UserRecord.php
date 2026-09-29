<?php

namespace App\Identity\Infrastructure;

use App\Identity\Domain\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

final class UserRecord extends Authenticatable
{
    use HasApiTokens, HasUuids;

    protected $table = 'users';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }

    public function role(): Role
    {
        $code = DB::table('user_roles')->where('user_id', $this->id)->value('role_code');

        return Role::from($code);
    }

    public function permits(string $permission): bool
    {
        return $this->is_active && $this->role()->allows($permission);
    }
}
