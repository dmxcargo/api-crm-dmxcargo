<?php

namespace App\Shared\Domain;

interface IdempotencyStore
{
    public function find(string $key, string $actorId, string $route): ?IdempotencyRecord;

    public function save(IdempotencyRecord $record, int $ttlHours): void;

    public function prune(): int;
}
