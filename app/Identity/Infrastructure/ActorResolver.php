<?php

namespace App\Identity\Infrastructure;

use App\Shared\Domain\BusinessRule;

final class ActorResolver
{
    // Job antrean hanya simpan ID aktor; izin dicek ulang saat job jalan.
    public function resolve(string $id, string $permission): UserRecord
    {
        $user = UserRecord::find($id);
        if (! $user || ! $user->permits($permission)) {
            throw new BusinessRule('FORBIDDEN', 'Akses pekerjaan tidak lagi diizinkan.', 403);
        }

        return $user;
    }
}
