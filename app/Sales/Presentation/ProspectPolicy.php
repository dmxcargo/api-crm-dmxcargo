<?php

namespace App\Sales\Presentation;

use App\Identity\Infrastructure\UserRecord;
use App\Sales\Infrastructure\ProspectRecord;
use Illuminate\Auth\Access\Response;

final class ProspectPolicy
{
    public function viewAny(UserRecord $actor): bool
    {
        return true;
    }

    public function create(UserRecord $actor): bool
    {
        return true;
    }

    public function view(UserRecord $actor, ProspectRecord $target): Response
    {
        return $this->owns($actor, $target) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(UserRecord $actor, ProspectRecord $target): Response
    {
        return $this->owns($actor, $target) ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(UserRecord $actor, ProspectRecord $target): Response
    {
        return $this->owns($actor, $target) ? Response::allow() : Response::denyAsNotFound();
    }

    public function assign(UserRecord $actor, ProspectRecord $target): bool
    {
        return $actor->permits('sales.all');
    }

    private function owns(UserRecord $actor, ProspectRecord $target): bool
    {
        return $actor->permits('sales.all') || $actor->id === $target->owner_user_id;
    }
}
