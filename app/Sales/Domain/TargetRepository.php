<?php

namespace App\Sales\Domain;

interface TargetRepository
{
    public function find(string $id, bool $lock = false): ?Target;

    public function existing(string $userId, TargetPeriod $type, string $period): ?Target;

    public function save(Target $target, string $actorId): void;

    /** @return Target[] */
    public function list(?string $userId, ?string $periodType, ?string $period): array;

    public function nextId(): string;
}
