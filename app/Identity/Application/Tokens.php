<?php

namespace App\Identity\Application;

interface Tokens
{
    public function issue(string $userId, string $device): array;

    public function revokeAll(string $userId): void;

    public function revokeCurrent(string $userId, string $tokenId): void;
}
