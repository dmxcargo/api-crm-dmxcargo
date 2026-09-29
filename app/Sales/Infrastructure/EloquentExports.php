<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\ExportJob;
use App\Sales\Domain\ExportRepository;
use App\Sales\Domain\ExportStatus;
use App\Sales\Domain\ExportType;
use Illuminate\Support\Str;

final class EloquentExports implements ExportRepository
{
    public function find(string $id, bool $lock = false): ?ExportJob
    {
        $record = ExportJobRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id);

        return $record ? $this->map($record) : null;
    }

    public function save(ExportJob $job): void
    {
        $record = ExportJobRecord::find($job->id) ?? new ExportJobRecord;
        $record->id = $job->id;
        if (! $record->exists) {
            $record->created_by = $job->createdBy;
        }
        $record->forceFill(['type' => $job->type->value, 'filters' => $job->filters, 'columns' => $job->columns,
            'status' => $job->status->value, 'path' => $job->path, 'expires_at' => $job->expiresAt,
            'result' => $job->result, 'version' => $job->version])->save();
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }

    private function map(ExportJobRecord $r): ExportJob
    {
        return new ExportJob($r->id, ExportType::from($r->type), $r->filters ?? [], $r->columns ?? [],
            ExportStatus::from($r->status), $r->path, $r->expires_at?->toISOString(), $r->result, $r->created_by, (int) $r->version);
    }
}
