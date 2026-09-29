<?php

namespace App\Identity\Domain;

interface AccountRepository
{
    public function find(string $id, bool $lock = false): ?Account;

    public function byLogin(string $login, bool $lock = false): ?Account;

    public function save(Account $account): void;

    public function activeAdmins(): int;

    public function lockAdministration(): void;

    public function nextId(): string;
}
