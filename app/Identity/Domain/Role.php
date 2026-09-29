<?php

namespace App\Identity\Domain;

enum Role: string
{
    case ADMIN = 'ADMIN';
    case BILLING = 'BILLING';
    case SALES = 'SALES';

    public function permissions(): array
    {
        $own = ['profile.read', 'sales.own', 'targets.read'];

        return match ($this) {
            self::SALES => $own,
            self::BILLING => [...$own, 'users.manage', 'roles.read', 'audit.read', 'sales.all', 'settings.manage', 'archives.manage', 'targets.read', 'payments.manage'],
            self::ADMIN => [...self::BILLING->permissions(), 'targets.manage'],
        };
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
