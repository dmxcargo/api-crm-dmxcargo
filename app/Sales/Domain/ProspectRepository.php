<?php

namespace App\Sales\Domain;

interface ProspectRepository
{
    public function find(string $id, bool $lock = false): ?Prospect;

    public function save(Prospect $prospect, string $actorId): void;

    /** @return DuplicateCandidate[] */
    public function candidates(?string $legacyId, ?string $phoneNormalized, ?string $email, ?string $accountName, ?string $city, ?string $excludeId = null): array;

    public function touch(string $id, ?bool $archived = null, bool $deleted = false): void;

    public function masterActive(string $table, string $code): bool;

    public function masters(string $table): array;

    public function nextId(): string;
}
