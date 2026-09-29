<?php

namespace App\Identity\Domain;

use App\Shared\Domain\BusinessRule;

final class Account
{
    public function __construct(
        public string $id, public string $name, public string $username, public string $email,
        public string $passwordHash, public Role $role, public bool $active = true, public int $version = 1,
    ) {
        $identity = new LoginIdentity($username, $email);
        $this->username = $identity->username;
        $this->email = $identity->email;
        $this->validateName($name);
    }

    private function validateName(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name) > 150) {
            throw new BusinessRule('INVALID_NAME', 'Nama wajib diisi dan maksimal 150 karakter.', 422);
        }
    }

    public function revise(array $data, int $activeAdmins): void
    {
        if ((int) $data['version'] !== $this->version) {
            throw new BusinessRule('VERSION_CONFLICT', 'Data telah diubah pengguna lain. Muat ulang data sebelum menyimpan.');
        }
        $role = isset($data['role']) ? Role::from($data['role']) : $this->role;
        $active = $data['isActive'] ?? $this->active;
        if ($this->active && $this->role === Role::ADMIN && $activeAdmins <= 1 && (! $active || $role !== Role::ADMIN)) {
            throw new BusinessRule('LAST_ADMIN_REQUIRED', 'Admin aktif terakhir tidak boleh dinonaktifkan atau diganti role. Siapkan Admin pengganti terlebih dahulu.');
        }
        $identity = new LoginIdentity($data['username'] ?? $this->username, $data['email'] ?? $this->email);
        $this->validateName($data['name'] ?? $this->name);
        $this->name = $data['name'] ?? $this->name;
        $this->username = $identity->username;
        $this->email = $identity->email;
        $this->role = $role;
        $this->active = $active;
        $this->version++;
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'username' => $this->username, 'email' => $this->email,
            'role' => $this->role->value, 'access' => $this->role === Role::SALES ? 'OWN' : 'ALL',
            'isActive' => $this->active, 'version' => $this->version, 'permissions' => $this->role->permissions()];
    }

    public function auditData(): array
    {
        return ['role' => $this->role->value, 'isActive' => $this->active, 'version' => $this->version];
    }
}
