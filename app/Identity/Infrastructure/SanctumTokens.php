<?php

namespace App\Identity\Infrastructure;

use App\Identity\Application\Tokens;

final class SanctumTokens implements Tokens
{
    public function issue(string $userId, string $device): array
    {
        $expires = now()->addMinutes(config('dmx.token_expiration_minutes'));
        $token = UserRecord::findOrFail($userId)->createToken($device, ['*'], $expires);

        return ['accessToken' => $token->plainTextToken, 'tokenType' => 'Bearer', 'expiresAt' => $expires->toISOString()];
    }

    public function revokeAll(string $userId): void
    {
        UserRecord::findOrFail($userId)->tokens()->delete();
    }

    public function revokeCurrent(string $userId, string $tokenId): void
    {
        UserRecord::findOrFail($userId)->tokens()->whereKey($tokenId)->delete();
    }
}
