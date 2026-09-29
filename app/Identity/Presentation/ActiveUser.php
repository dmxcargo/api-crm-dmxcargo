<?php

namespace App\Identity\Presentation;

use App\Shared\Domain\BusinessRule;
use Closure;

final class ActiveUser
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            $user?->tokens()->delete();
            throw new BusinessRule('SESSION_EXPIRED', 'Akun tidak aktif atau sesi telah berakhir. Silakan hubungi Admin.', 401);
        }

        return $next($request);
    }
}
