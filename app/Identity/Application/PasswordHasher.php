<?php

namespace App\Identity\Application;

interface PasswordHasher
{
    public function hash(string $plain): string;

    public function check(string $plain, string $hash): bool;
}
