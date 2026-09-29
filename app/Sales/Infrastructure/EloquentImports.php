<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\ImportDecision;
use App\Sales\Domain\ImportJob;
use App\Sales\Domain\ImportJobStatus;
use App\Sales\Domain\ImportRepository;
use App\Sales\Domain\ImportRow;
use App\Sales\Domain\ImportRowStatus;
use Illuminate\Support\Str;

final class EloquentImports implements ImportRepository
{
    public function findJob(string $id, bool $lock = false): ?ImportJob
    {
        $record = ImportJobRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id);

        return $record ? $this->mapJob($record) : null;
    }

    public function saveJob(ImportJob $job): void
    {
        $record = ImportJobRecord::find($job->id) ?? new ImportJobRecord;
        $record->id = $job->id;
        $record->forceFill(['schema_version' => $job->schemaVersion, 'source_system' => $job->sourceSystem,
            'file_sha256' => $job->fileSha256, 'file_name' => $job->fileName, 'status' => $job->status->value,
            'total_rows' => $job->totalRows, 'counters' => $job->counters,
            'created_by' => $job->createdBy, 'version' => $job->version])->save();
    }

    public function insertRows(ImportJob $job, array $rows): void
    {
        $now = now();
        foreach (array_chunk($rows, 500) as $chunk) {
            ImportRowRecord::insert(collect($chunk)->map(fn ($r) => ['id' => (string) Str::uuid(), 'job_id' => $job->id,
                'row_number' => $r['rowNumber'], 'raw' => json_encode($r['data']), 'status' => ImportRowStatus::INVALID->value,
                'codes' => json_encode(['NOT_VALIDATED']), 'created_at' => $now, 'updated_at' => $now])->all());
        }
    }

    public function findRow(string $jobId, int $rowNumber, bool $lock = false): ?ImportRow
    {
        $record = ImportRowRecord::query()->where('job_id', $jobId)->where('row_number', $rowNumber)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();

        return $record ? $this->mapRow($record) : null;
    }

    public function saveRow(ImportRow $row): void
    {
        $record = ImportRowRecord::find($row->id) ?? new ImportRowRecord;
        $record->id = $row->id;
        $record->forceFill(['job_id' => $row->jobId, 'row_number' => $row->rowNumber, 'raw' => $row->raw,
            'normalized' => $row->normalized, 'status' => $row->status->value, 'codes' => $row->codes,
            'decision' => $row->decision?->value, 'approved_fields' => $row->approvedFields,
            'prospect_id' => $row->prospectId, 'result' => $row->result, 'version' => $row->version])->save();
    }

    public function committableSlice(string $jobId, int $limit): array
    {
        return ImportRowRecord::query()->where('job_id', $jobId)
            ->whereIn('status', [ImportRowStatus::VALID->value, ImportRowStatus::WARNING->value, ImportRowStatus::DUPLICATE->value])
            ->orderBy('row_number')->limit($limit)->get()->map(fn ($r) => $this->mapRow($r))->all();
    }

    public function committableCount(string $jobId): int
    {
        return ImportRowRecord::query()->where('job_id', $jobId)
            ->whereIn('status', [ImportRowStatus::VALID->value, ImportRowStatus::WARNING->value, ImportRowStatus::DUPLICATE->value])->count();
    }

    public function pendingSignature(string $jobId): string
    {
        $byStatus = ImportRowRecord::query()->where('job_id', $jobId)
            ->whereIn('status', [ImportRowStatus::VALID->value, ImportRowStatus::WARNING->value, ImportRowStatus::DUPLICATE->value])
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return 'V:'.(int) ($byStatus[ImportRowStatus::VALID->value] ?? 0)
            .'|W:'.(int) ($byStatus[ImportRowStatus::WARNING->value] ?? 0)
            .'|D:'.(int) ($byStatus[ImportRowStatus::DUPLICATE->value] ?? 0);
    }

    public function counts(string $jobId): array
    {
        $byStatus = ImportRowRecord::query()->where('job_id', $jobId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        $out = [];
        foreach (ImportRowStatus::cases() as $s) {
            $out[$s->value] = (int) ($byStatus[$s->value] ?? 0);
        }
        $out['inserted'] = ImportRowRecord::query()->where('job_id', $jobId)
            ->where('status', ImportRowStatus::IMPORTED->value)->where('decision', ImportDecision::CREATE_NEW->value)->count();
        $out['updated'] = ImportRowRecord::query()->where('job_id', $jobId)
            ->where('status', ImportRowStatus::IMPORTED->value)->whereIn('decision',
                [ImportDecision::UPDATE_EXISTING->value, ImportDecision::MERGE_SELECTED_FIELDS->value])->count();
        $out['skipped'] = (int) ($byStatus[ImportRowStatus::SKIPPED->value] ?? 0);
        $out['failed'] = ImportRowRecord::query()->where('job_id', $jobId)
            ->where('status', ImportRowStatus::INVALID->value)->whereNotNull('result')->count();

        return $out;
    }

    public function page(string $jobId, ?string $status, int $page, int $pageSize): array
    {
        $q = ImportRowRecord::query()->where('job_id', $jobId)->when($status, fn ($w) => $w->where('status', $status))->orderBy('row_number');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $pageSize)->get()->map(fn ($r) => $this->mapRow($r))->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function failures(string $jobId): array
    {
        return ImportRowRecord::query()->where('job_id', $jobId)->where('status', ImportRowStatus::INVALID->value)
            ->orderBy('row_number')->get()->map(fn ($r) => $this->mapRow($r))->all();
    }

    public function shaExists(string $sha256, string $excludeJobId): bool
    {
        return ImportJobRecord::query()->where('file_sha256', $sha256)
            ->when($excludeJobId !== '', fn ($q) => $q->where('id', '!=', $excludeJobId))->exists();
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }

    private function mapJob(ImportJobRecord $r): ImportJob
    {
        return new ImportJob($r->id, (int) $r->schema_version, $r->source_system, $r->file_sha256,
            $r->file_name, ImportJobStatus::from($r->status), (int) $r->total_rows, $r->counters ?? [],
            $r->created_by, (int) $r->version);
    }

    private function mapRow(ImportRowRecord $r): ImportRow
    {
        return new ImportRow($r->id, $r->job_id, (int) $r->row_number, $r->raw ?? [],
            $r->normalized, ImportRowStatus::from($r->status), $r->codes ?? [],
            $r->decision ? ImportDecision::from($r->decision) : null, $r->approved_fields,
            $r->prospect_id, $r->result, (int) $r->version);
    }
}
