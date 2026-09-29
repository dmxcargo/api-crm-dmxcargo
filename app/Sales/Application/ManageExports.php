<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Sales\Domain\ExportJob;
use App\Sales\Domain\ExportRepository;
use App\Sales\Domain\ExportStatus;
use App\Sales\Domain\ExportType;
use App\Sales\Domain\TargetPeriod;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ManageExports
{
    private const PROSPECT_COLUMNS = ['external_id', 'entry_date', 'account_name', 'pic_name', 'pic_position',
        'phone', 'phone_normalized', 'email', 'city', 'province', 'industry', 'prospect_source', 'owner_username',
        'stage', 'priority', 'last_progress', 'next_follow_up_at', 'next_action', 'potential_value',
        'payment_status', 'customer_type', 'notes', 'legacy_source_row'];

    private const PERFORMANCE_COLUMNS = ['owner_name', 'period', 'target_value', 'actual_value', 'achievement',
        'closing_rate', 'total_prospect', 'closing_count', 'overdue_follow_ups', 'calls', 'calls_answered', 'visits'];

    public function __construct(private ExportRepository $exports, private MetricsService $metrics,
        private UnitOfWork $transactions, private AuditWriter $audit) {}

    public static function prospectColumns(): array
    {
        return self::PROSPECT_COLUMNS;
    }

    public static function performanceColumns(): array
    {
        return self::PERFORMANCE_COLUMNS;
    }

    public function create(string $actorId, ExportType $type, array $filters, ?array $columns, ?string $scopeOwner): ExportJob
    {
        $allow = $type === ExportType::PROSPECT ? self::PROSPECT_COLUMNS : self::PERFORMANCE_COLUMNS;
        $columns ??= $allow;
        if ($columns === [] || array_diff($columns, $allow) !== []) {
            throw new BusinessRule('INVALID_COLUMNS', 'Kolom export tidak valid.', 422);
        }
        if ($type === ExportType::PERFORMANCE && (empty($filters['periodType']) || empty($filters['period']))) {
            throw new BusinessRule('MISSING_REQUIRED_FIELD', 'Periode wajib diisi untuk export performa.', 422);
        }

        return $this->transactions->run(function () use ($actorId, $type, $filters, $columns, $scopeOwner) {
            $job = new ExportJob($this->exports->nextId(), $type,
                [...$filters, 'ownerScope' => $scopeOwner], $columns, ExportStatus::QUEUED, null, null, null, $actorId);
            $this->exports->save($job);
            $this->audit->record('export.created', $actorId, $job->id, [], ['type' => $type->value], 'export');

            return $job;
        });
    }

    public function run(string $jobId): void
    {
        $job = $this->exports->find($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($job->status !== ExportStatus::QUEUED) {
            throw new BusinessRule('IMPORT_LOCKED', 'Job tidak dalam status yang dapat diproses.', 409);
        }
        $job->status = ExportStatus::PROCESSING;
        $this->exports->save($job);
        try {
            $rows = $job->type === ExportType::PROSPECT ? $this->prospectRows($job) : $this->performanceRows($job);
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, $job->columns);
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn ($c) => CsvSanitizer::cell($row[$c] ?? ''), $job->columns));
            }
            rewind($stream);
            $path = "exports/{$job->id}.csv";
            Storage::disk('private')->put($path, stream_get_contents($stream));
            fclose($stream);
            $job->status = ExportStatus::DONE;
            $job->path = $path;
            $job->expiresAt = now()->addHours(config('dmx.export_ttl_hours', 24))->toISOString();
            $this->exports->save($job);
            $this->audit->record('export.completed', $job->createdBy, $jobId, [], ['rows' => count($rows)], 'export');
        } catch (\Throwable $e) {
            $job->status = ExportStatus::FAILED;
            $job->result = $e instanceof BusinessRule ? $e->errorCode.': '.$e->getMessage() : 'EXPORT_FAILED: export gagal diproses.';
            $this->exports->save($job);
            throw $e;
        }
    }

    public function downloadPath(string $jobId, string $requesterId, bool $privileged): string
    {
        $job = $this->exports->find($jobId) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if (! $privileged) {
            if ($job->createdBy !== $requesterId || ($job->filters['ownerScope'] ?? $requesterId) !== $requesterId) {
                throw new BusinessRule('FORBIDDEN', 'Anda tidak memiliki izin untuk tindakan ini.', 403);
            }
        }
        if ($job->status !== ExportStatus::DONE || ! $job->path) {
            throw new BusinessRule('NOT_FOUND', 'File export belum tersedia.', 404);
        }
        if ($job->expiresAt && now()->greaterThan(Carbon::parse($job->expiresAt))) {
            throw new BusinessRule('DOWNLOAD_EXPIRED', 'Tautan unduhan sudah kedaluwarsa. Buat export baru.', 410);
        }
        if (! Storage::disk('private')->exists($job->path)) {
            throw new BusinessRule('NOT_FOUND', 'File export tidak ditemukan.', 404);
        }

        return Storage::disk('private')->path($job->path);
    }

    private function prospectRows(ExportJob $job): array
    {
        $f = $job->filters;
        $out = [];
        DB::table('prospects')->leftJoin('users', 'users.id', '=', 'prospects.owner_user_id')
            ->whereNull('prospects.deleted_at')
            ->when($f['ownerScope'] ?? null, fn ($q, $o) => $q->where('prospects.owner_user_id', $o))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('prospects.account_name', 'ilike', "%$term%")->orWhere('prospects.phone_raw', 'like', "%$term%")))
            ->when($f['stage'] ?? null, fn ($q, $v) => $q->where('prospects.stage', $v))
            ->when($f['priority'] ?? null, fn ($q) => $q->where('prospects.priority', $f['priority']))
            ->when($f['city'] ?? null, fn ($q) => $q->where('prospects.city', $f['city']))
            ->when($f['sourceCode'] ?? null, fn ($q) => $q->where('prospects.source_code', $f['sourceCode']))
            ->when($f['dateFrom'] ?? null, fn ($q) => $q->whereDate('prospects.entry_date', '>=', $f['dateFrom']))
            ->when($f['dateTo'] ?? null, fn ($q) => $q->whereDate('prospects.entry_date', '<=', $f['dateTo']))
            ->orderBy('prospects.entry_date')->orderBy('prospects.id')
            ->select(['prospects.legacy_id', 'prospects.entry_date', 'prospects.account_name', 'prospects.pic_name',
                'prospects.pic_position', 'prospects.phone_raw', 'prospects.phone_normalized', 'prospects.email',
                'prospects.city', 'prospects.province', 'prospects.industry_code', 'prospects.source_code',
                'users.normalized_username as owner_username', 'prospects.stage', 'prospects.priority', 'prospects.last_progress',
                'prospects.next_follow_up_at', 'prospects.next_action', 'prospects.potential_value',
                'prospects.payment_status', 'prospects.customer_type', 'prospects.notes', 'prospects.legacy_source_row'])
            ->chunk(1000, function ($chunk) use (&$out) {
                foreach ($chunk as $r) {
                    $out[] = ['external_id' => $r->legacy_id, 'entry_date' => $r->entry_date,
                        'account_name' => $r->account_name, 'pic_name' => $r->pic_name, 'pic_position' => $r->pic_position,
                        'phone' => $r->phone_raw, 'phone_normalized' => $r->phone_normalized, 'email' => $r->email,
                        'city' => $r->city, 'province' => $r->province, 'industry' => $r->industry_code,
                        'prospect_source' => $r->source_code, 'owner_username' => $r->owner_username,
                        'stage' => $r->stage, 'priority' => $r->priority, 'last_progress' => $r->last_progress,
                        'next_follow_up_at' => $r->next_follow_up_at, 'next_action' => $r->next_action,
                        'potential_value' => $r->potential_value, 'payment_status' => $r->payment_status,
                        'customer_type' => $r->customer_type, 'notes' => $r->notes,
                        'legacy_source_row' => $r->legacy_source_row];
                }
            });

        return $out;
    }

    private function performanceRows(ExportJob $job): array
    {
        $f = $job->filters;
        $type = TargetPeriod::from($f['periodType']);
        $owners = $this->metrics->ownersInScope($f['ownerScope'] ?? null, $type, $f['period']);
        $names = $this->metrics->names($owners);
        $out = [];
        foreach ($owners as $ownerId) {
            $m = $this->metrics->perOwner($ownerId, $type, $f['period']);
            $calls = $visits = $answered = 0;
            foreach ($m['activities'] as $a) {
                if ($a->type === 'CALL') {
                    $calls += (int) $a->c;
                    $answered += (int) $a->answered;
                } elseif ($a->type === 'VISIT') {
                    $visits += (int) $a->c;
                }
            }
            $out[] = ['owner_name' => $names[$ownerId] ?? $ownerId, 'period' => $f['period'],
                'target_value' => $m['targetValue'], 'actual_value' => $m['actualValue'],
                'achievement' => $m['achievement'], 'closing_rate' => $m['closingRate'],
                'total_prospect' => $m['totalProspect'], 'closing_count' => $m['closingCount'],
                'overdue_follow_ups' => $m['overdueFollowUps'], 'calls' => $calls,
                'calls_answered' => $answered, 'visits' => $visits];
        }

        return $out;
    }
}
