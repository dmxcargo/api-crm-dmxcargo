<?php

namespace App\Audit\Application;

interface AuditWriter
{
    public function record(string $action, ?string $actorId, ?string $entityId, array $before = [], array $after = [], string $entityType = 'user'): void;
}
