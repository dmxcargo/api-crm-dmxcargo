<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Sales\Domain\ArchiveJob;
use App\Sales\Domain\ArchiveRepository;
use App\Sales\Domain\ArchiveStatus;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ManageArchives
{
    private const HEADER = ['id', 'legacy_id', 'entry_date', 'account_name', 'phone_raw', 'phone_normalized',
        'email', 'city', 'stage', 'priority', 'owner_user_id', 'customer_type', 'potential_value', 'created_at'];

    public function __construct(private ArchiveRepository $archives, private UnitOfWork $transactions, private AuditWriter $audit) {}

    public function preview(array $filters): array
    {
        $q = $this->candidates($filters);
        $range = (clone $q)->selectRaw('count(*) as c, min(entry_date) as from_date, max(entry_date) as to_date')->first();

        return ['eligible' => (int) $range->c, 'entryDateFrom' => $range->from_date, 'entryDateTo' => $range->to_date];
    }

    public function create(string $actorId, array $filters): ArchiveJob
    {
        return $this->transactions->run(function () use ($actorId, $filters) {
            $job = new ArchiveJob($this->archives->nextId(), 'PROSPECT', $filters, ArchiveStatus::QUEUED,
                null, null, null, null, false, false, null, $actorId);
            $this->archives->save($job);
            $this->audit->record('archive.created', $actorId, $job->id, [], ['filters' => $filters], 'archive');

            return $job;
        });
    }

    public function build(string $jobId): void
    {
        $job = $this->archives->find($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($job->status !== ArchiveStatus::QUEUED) {
            throw new BusinessRule('IMPORT_LOCKED', 'Job tidak dalam status yang dapat diproses.', 409);
        }
        $job->status = ArchiveStatus::PROCESSING;
        $this->archives->save($job);
        try {
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, self::HEADER);
            $count = 0;
            $from = $to = null;
            $this->candidates($job->filters)->orderBy('id')->chunk(1000, function ($chunk) use ($stream, &$count, &$from, &$to) {
                foreach ($chunk as $r) {
                    fputcsv($stream, [$r->id, $r->legacy_id, $r->entry_date, $r->account_name, $r->phone_raw,
                        $r->phone_normalized, $r->email, $r->city, $r->stage, $r->priority, $r->owner_user_id,
                        $r->customer_type, $r->potential_value, $r->created_at]);
                    $count++;
                    $from = $from === null || $r->entry_date < $from ? $r->entry_date : $from;
                    $to = $to === null || $r->entry_date > $to ? $r->entry_date : $to;
                }
            });
            rewind($stream);
            $gz = gzencode(stream_get_contents($stream));
            fclose($stream);
            $path = "archives/{$job->id}.csv.gz";
            Storage::disk('private')->put($path, $gz);
            $job->status = ArchiveStatus::READY;
            $job->path = $path;
            $job->sha256 = hash('sha256', $gz);
            $job->manifest = ['schemaVersion' => 1, 'recordCount' => $count, 'entryDateFrom' => $from,
                'entryDateTo' => $to, 'sha256' => $job->sha256, 'filters' => $job->filters,
                'createdBy' => $job->createdBy, 'createdAt' => now()->toISOString()];
            $this->archives->save($job);
            $this->audit->record('archive.built', $job->createdBy, $jobId, [], ['recordCount' => $count], 'archive');
        } catch (\Throwable $e) {
            $job->status = ArchiveStatus::FAILED;
            $job->result = $e instanceof BusinessRule ? $e->errorCode.': '.$e->getMessage() : 'ARCHIVE_FAILED: arsip gagal dibuat.';
            $this->archives->save($job);
            throw $e;
        }
    }

    public function verify(string $actorId, string $jobId): array
    {
        $job = $this->archives->find($jobId) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($job->status !== ArchiveStatus::READY || ! $job->path) {
            throw new BusinessRule('NOT_FOUND', 'File arsip belum tersedia.', 404);
        }
        $gz = Storage::disk('private')->get($job->path)
            ?? throw new BusinessRule('NOT_FOUND', 'File arsip tidak ditemukan.', 404);
        $out = ['checksum' => hash('sha256', $gz) === $job->sha256, 'readable' => false, 'countMatch' => false];
        $csv = @gzdecode($gz);
        if ($csv !== false) {
            [, $rows] = CsvParser::parse($csv);
            $out['readable'] = true;
            $out['countMatch'] = count($rows) === ($job->manifest['recordCount'] ?? -1);
            $out['rows'] = count($rows);
        }
        $out['valid'] = $out['checksum'] && $out['readable'] && $out['countMatch'];
        $this->audit->record('archive.verified', $actorId, $jobId, [], $out, 'archive');

        return $out;
    }

    public function secondCopy(string $actorId, string $jobId): ArchiveJob
    {
        return $this->transactions->run(function () use ($actorId, $jobId) {
            $job = $this->archives->find($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ($job->status !== ArchiveStatus::READY || ! $job->path) {
                throw new BusinessRule('NOT_FOUND', 'File arsip belum tersedia.', 404);
            }
            $copy = "archives/copy-{$job->id}.csv.gz";
            Storage::disk('private')->copy($job->path, $copy);
            $job->secondCopyPath = $copy;
            $job->secondCopyVerified = hash('sha256', Storage::disk('private')->get($copy)) === $job->sha256;
            $this->archives->save($job);
            $this->audit->record('archive.copied', $actorId, $jobId, [],
                ['secondCopyVerified' => $job->secondCopyVerified], 'archive');

            return $job;
        });
    }

    public function restore(string $actorId, string $jobId): array
    {
        $job = $this->archives->find($jobId) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($job->status !== ArchiveStatus::READY || ! $job->path) {
            throw new BusinessRule('NOT_FOUND', 'File arsip belum tersedia.', 404);
        }
        $gz = Storage::disk('private')->get($job->path);
        if ($gz === null || hash('sha256', $gz) !== $job->sha256) {
            throw new BusinessRule('CHECKSUM_MISMATCH', 'Checksum arsip tidak cocok. Restore dihentikan.', 422);
        }
        $csv = gzdecode($gz);
        if ($csv === false) {
            throw new BusinessRule('CORRUPT_ARCHIVE', 'File arsip tidak dapat dibaca.', 422);
        }
        [$header, $rows] = CsvParser::parse($csv);
        if ($header !== self::HEADER) {
            throw new BusinessRule('SCHEMA_MISMATCH', 'Skema arsip tidak dikenali.', 422);
        }

        return $this->transactions->run(function () use ($actorId, $job, $rows) {
            DB::table('archive_staging')->where('job_id', $job->id)->delete();
            $ids = [];
            $phones = [];
            foreach ($rows as $row) {
                if ($row['data']['legacy_id'] ?? null) {
                    $ids[$row['data']['legacy_id']] = true;
                }
                if ($row['data']['phone_normalized'] ?? null) {
                    $phones[$row['data']['phone_normalized']] = true;
                }
            }
            $liveIds = DB::table('prospects')->whereNull('deleted_at')->whereIn('legacy_id', array_keys($ids))->pluck('legacy_id')->all();
            $livePhones = DB::table('prospects')->whereNull('deleted_at')->whereIn('phone_normalized', array_keys($phones))->pluck('phone_normalized')->all();
            $liveIds = array_flip($liveIds);
            $livePhones = array_flip($livePhones);
            $staged = [];
            foreach ($rows as $row) {
                $d = $row['data'];
                $staged[] = ['rowNumber' => $row['rowNumber'], 'data' => $d,
                    'conflict' => isset($liveIds[$d['legacy_id'] ?? '']) || isset($livePhones[$d['phone_normalized'] ?? ''])];
            }
            $this->archives->insertStaging($job->id, $staged);
            $conflicts = count(array_filter($staged, fn ($r) => $r['conflict']));
            $this->audit->record('archive.restored', $actorId, $job->id, [],
                ['staged' => count($staged), 'conflicts' => $conflicts], 'archive');

            return ['staged' => count($staged), 'conflicts' => $conflicts];
        });
    }

    public function purge(string $actorId, string $jobId, bool $approved): ArchiveJob
    {
        if (! config('dmx.archive_purge_enabled', false) || ! $approved) {
            throw new BusinessRule('ARCHIVE_LOCKED', 'Purge dikunci. Aktifkan konfigurasi dan persetujuan eksplisit.', 403);
        }

        return $this->transactions->run(function () use ($actorId, $jobId) {
            $job = $this->archives->find($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ($job->status !== ArchiveStatus::READY) {
                throw new BusinessRule('IMPORT_LOCKED', 'Job tidak dalam status yang dapat di-purge.', 409);
            }
            if (! $job->secondCopyVerified) {
                throw new BusinessRule('SECOND_COPY_REQUIRED', 'Purge wajib salinan kedua terverifikasi.', 422);
            }
            if (empty($job->filters['dateTo'])) {
                throw new BusinessRule('RETENTION_REQUIRED', 'Purge wajib batas retensi (dateTo) eksplisit.', 422);
            }
            if ($job->purged) {
                return $job;
            }
            $count = 0;
            $this->candidates($job->filters)->orderBy('id')->chunkById(500, function ($chunk) use (&$count) {
                $ids = $chunk->pluck('id')->all();
                DB::table('prospect_contacts')->whereIn('prospect_id', $ids)->delete();
                $count += DB::table('prospects')->whereIn('id', $ids)->update(['deleted_at' => now()]);
            });
            $job->purged = true;
            $job->result = "purged $count prospects (soft delete; deals/customers/history retained)";
            $this->archives->save($job);
            $this->audit->record('archive.purged', $actorId, $jobId, [], ['purged' => $count], 'archive');

            return $job;
        });
    }

    private function candidates(array $filters)
    {
        return DB::table('prospects')->whereNull('deleted_at')->whereIn('stage', ['WON', 'LOST'])
            ->when($filters['owner'] ?? null, fn ($q, $o) => $q->where('owner_user_id', $o))
            ->when($filters['dateFrom'] ?? null, fn ($q) => $q->whereDate('entry_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'] ?? null, fn ($q) => $q->whereDate('entry_date', '<=', $filters['dateTo']));
    }
}
