<?php

namespace App\Identity\Infrastructure;

use App\Identity\Domain\Account;
use App\Identity\Domain\AccountRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentAccounts implements AccountRepository
{
    public function find(string $id, bool $lock = false): ?Account
    {
        return $this->map(UserRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id));
    }

    public function byLogin(string $login, bool $lock = false): ?Account
    {
        return $this->map(UserRecord::query()->where(str_contains($login, '@') ? 'normalized_email' : 'normalized_username', strtolower(trim($login)))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first());
    }

    public function map(?UserRecord $record): ?Account
    {
        if (! $record) {
            return null;
        }
        try {
            $role = $record->role();
        } catch (\ValueError|\TypeError) {
            return null;
        }

        return new Account($record->id, $record->name, $record->normalized_username, $record->normalized_email,
            $record->password, $role, $record->is_active, $record->version);
    }

    public function save(Account $account): void
    {
        $record = UserRecord::find($account->id) ?? new UserRecord;
        $record->id = $account->id;
        $record->forceFill(['name' => $account->name, 'normalized_username' => $account->username, 'normalized_email' => $account->email,
            'password' => $account->passwordHash, 'is_active' => $account->active, 'version' => $account->version])->save();
        DB::table('user_roles')->updateOrInsert(['user_id' => $account->id], ['role_code' => $account->role->value]);
    }

    public function activeAdmins(): int
    {
        return DB::table('users')->join('user_roles', 'users.id', '=', 'user_roles.user_id')->where('role_code', 'ADMIN')->where('is_active', true)->count();
    }

    public function lockAdministration(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(7301001)');
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }
}
