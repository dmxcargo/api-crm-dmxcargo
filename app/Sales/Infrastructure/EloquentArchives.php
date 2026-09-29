<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\ArchiveJob;
use App\Sales\Domain\ArchiveRepository;
use App\Sales\Domain\ArchiveStatus;
use Illuminate\Support\Str;

final class EloquentArchives implements ArchiveRepository
{
    public function find(string $id, bool $lock = false): ?ArchiveJob
    {
        $record = ArchiveJobRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id);

        return $record ? $this->map($record) : null;
    }

    public function save(ArchiveJob $job): void
    {
        $record = ArchiveJobRecord::find($job->id) ?? new ArchiveJobRecord;
        $record->id = $job->id;
        if (! $record->exists) {
            $record->created_by = $job->createdBy;
        }
        $record->forceFill(['type' => $job->type, 'filters' => $job->filters, 'status' => $job->status->value,
            'manifest' => $job->manifest, 'path' => $job->path, 'sha256' => $job->sha256,
            'second_copy_path' => $job->secondCopyPath, 'second_copy_verified' => $job->secondCopyVerified,
            'purged' => $job->purged, 'result' => $job->result, 'version' => $job->version])->save();
    }

    public function insertStaging(string $jobId, array $rows): void
    {
        $now = now();
        foreach (array_chunk($rows, 500) as $chunk) {
            ArchiveStagingRecord::insert(collect($chunk)->map(fn ($r) => ['id' => (string) Str::uuid(),
                'job_id' => $jobId, 'row_number' => $r['rowNumber'], 'raw' => json_encode($r['data']),
                'status' => $r['conflict'] ? 'CONFLICT' : 'STAGED',
                'created_at' => $now, 'updated_at' => $now])->all());
        }
    }

    public function stagingPage(string $jobId, ?string $status, int $page, int $pageSize): array
    {
        $q = ArchiveStagingRecord::query()->where('job_id', $jobId)
            ->when($status, fn ($w) => $w->where('status', $status))->orderBy('row_number');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $pageSize)->get()
            ->map(fn ($r) => ['id' => $r->id, 'status' => $r->status,
                'accountName' => $r->raw['account_name'] ?? null,
                'conflict' => $r->status === 'CONFLICT' ? 'Duplikat data aktif' : null])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function stagingCount(string $jobId, ?string $status = null): int
    {
        return ArchiveStagingRecord::query()->where('job_id', $jobId)
            ->when($status, fn ($q) => $q->where('status', $status))->count();
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }

    private function map(ArchiveJobRecord $r): ArchiveJob
    {
        return new ArchiveJob($r->id, $r->type, $r->filters ?? [], ArchiveStatus::from($r->status),
            $r->manifest, $r->path, $r->sha256, $r->second_copy_path, (bool) $r->second_copy_verified,
            (bool) $r->purged, $r->result, $r->created_by, (int) $r->version);
    }
}
