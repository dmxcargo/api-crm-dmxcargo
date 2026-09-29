<?php

namespace App\Identity\Domain;

use App\Shared\Domain\BusinessRule;

final readonly class LoginIdentity
{
    public string $username;

    public string $email;

    public function __construct(string $username, string $email)
    {
        $username = strtolower(trim($username));
        $email = strtolower(trim($email));
        if (! preg_match('/^[a-z0-9_.-]{3,50}$/D', $username) || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessRule('INVALID_IDENTITY', 'Username atau email tidak valid.', 422);
        }
        $this->username = $username;
        $this->email = $email;
    }
}
