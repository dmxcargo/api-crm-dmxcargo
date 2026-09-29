<?php

namespace App\Sales\Domain;

interface ArchiveRepository
{
    public function find(string $id, bool $lock = false): ?ArchiveJob;

    public function save(ArchiveJob $job): void;

    public function insertStaging(string $jobId, array $rows): void;

    /** @return array{rows: array, total: int} */
    public function stagingPage(string $jobId, ?string $status, int $page, int $pageSize): array;

    public function stagingCount(string $jobId, ?string $status = null): int;

    public function nextId(): string;
}
