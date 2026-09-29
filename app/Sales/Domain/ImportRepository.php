<?php

namespace App\Sales\Domain;

interface ImportRepository
{
    public function findJob(string $id, bool $lock = false): ?ImportJob;

    public function saveJob(ImportJob $job): void;

    public function insertRows(ImportJob $job, array $rows): void;

    public function findRow(string $jobId, int $rowNumber, bool $lock = false): ?ImportRow;

    public function saveRow(ImportRow $row): void;

    /** @return ImportRow[] */
    public function committableSlice(string $jobId, int $limit): array;

    public function committableCount(string $jobId): int;

    public function pendingSignature(string $jobId): string;

    public function counts(string $jobId): array;

    /** @return array{rows: ImportRow[], total: int} */
    public function page(string $jobId, ?string $status, int $page, int $pageSize): array;

    /** @return ImportRow[] */
    public function failures(string $jobId): array;

    public function shaExists(string $sha256, string $excludeJobId): bool;

    public function nextId(): string;
}
