<?php

namespace App\Identity\Presentation;

use App\Identity\Infrastructure\UserRecord;
use Illuminate\Auth\Access\Response;

final class UserPolicy
{
    public function viewAny(UserRecord $actor): bool
    {
        return $actor->permits('users.manage');
    }

    public function create(UserRecord $actor): bool
    {
        return $actor->permits('users.manage');
    }

    public function update(UserRecord $actor, UserRecord $target): bool
    {
        return $actor->permits('users.manage');
    }

    public function view(UserRecord $actor, UserRecord $target): Response
    {
        return ($actor->permits('users.manage') || $actor->id === $target->id) ? Response::allow() : Response::denyAsNotFound();
    }
}
