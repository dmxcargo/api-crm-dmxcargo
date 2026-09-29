<?php

namespace App\Shared\Infrastructure;

use App\Identity\Domain\Role;
use App\Identity\Infrastructure\UserRecord;
use App\Shared\Domain\BusinessRule;
use Illuminate\Database\Eloquent\Builder;

final class OwnedRecords
{
    public function scope(Builder $query, UserRecord $actor): Builder
    {
        if (! $actor->is_active) {
            throw new BusinessRule('SESSION_EXPIRED', 'Sesi tidak berlaku. Silakan masuk kembali.', 401);
        }

        return $actor->role() === Role::SALES ? $query->where($query->getModel()->qualifyColumn('owner_user_id'), $actor->id) : $query;
    }
}
