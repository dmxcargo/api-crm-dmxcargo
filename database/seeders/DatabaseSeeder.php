<?php

namespace Database\Seeders;

use App\Identity\Domain\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SalesMasterSeeder::class);
        DB::transaction(function () {
            foreach (Role::cases() as $role) {
                DB::table('roles')->updateOrInsert(['code' => $role->value]);
                foreach ($role->permissions() as $permission) {
                    DB::table('permissions')->updateOrInsert(['code' => $permission]);
                    DB::table('role_permissions')->updateOrInsert(['role_code' => $role->value, 'permission_code' => $permission]);
                }
            }
        });
    }
}
