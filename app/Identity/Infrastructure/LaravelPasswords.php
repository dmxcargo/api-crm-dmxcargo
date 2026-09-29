<?php

namespace App\Identity\Infrastructure;

use App\Identity\Application\PasswordHasher;
use Illuminate\Support\Facades\Hash;

final class LaravelPasswords implements PasswordHasher
{
    public function hash(string $plain): string
    {
        return Hash::make($plain);
    }

    public function check(string $plain, string $hash): bool
    {
        return Hash::check($plain, $hash);
    }
}
