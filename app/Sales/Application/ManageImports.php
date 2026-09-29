<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Sales\Domain\ImportDecision;
use App\Sales\Domain\ImportJob;
use App\Sales\Domain\ImportJobStatus;
use App\Sales\Domain\ImportRepository;
use App\Sales\Domain\ImportRow;
use App\Sales\Domain\ImportRowStatus;
use App\Sales\Domain\ProspectRepository;
use App\Sales\Domain\ProspectStage;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ManageImports
{
    private const MERGEABLE = ['accountName', 'picName', 'picPosition', 'phone', 'email', 'city', 'province',
        'industryCode', 'lastProgress', 'nextFollowUpAt', 'nextAction', 'potentialValue', 'paymentStatus', 'notes'];

    public function __construct(private ImportRepository $imports, private ImportNormalizer $normalizer,
        private ManageProspects $prospects, private ProspectRepository $prospectRepo, private ManageDeals $deals,
        private UnitOfWork $transactions, private AuditWriter $audit) {}

    public function create(string $actorId, string $fileName, string $content): ImportJob
    {
        if (strlen($content) > config('dmx.import_max_mb', 20) * 1024 * 1024) {
            throw new BusinessRule('PAYLOAD_TOO_LARGE', 'Ukuran file melebihi batas import.', 413);
        }
        [, $rows] = CsvParser::parse($content);
        if ($rows === []) {
            throw new BusinessRule('EMPTY_CSV', 'File CSV tidak berisi data.', 422);
        }
        if (count($rows) > config('dmx.import_max_rows', 100000)) {
            throw new BusinessRule('TOO_MANY_ROWS', 'Jumlah baris melebihi batas import.', 422);
        }
        $sha = hash('sha256', $content);

        return $this->transactions->run(function () use ($actorId, $fileName, $rows, $sha) {
            $job = new ImportJob($this->imports->nextId(), config('dmx.import_schema_version', 1), 'LEGACY_XLSX',
                $sha, $fileName, ImportJobStatus::DRAFT, count($rows),
                ['duplicateFile' => $this->imports->shaExists($sha, '')], $actorId);
            $this->imports->saveJob($job);
            $this->imports->insertRows($job, $rows);
            $this->audit->record('import.created', $actorId, $job->id,
                [], ['fileName' => $fileName, 'totalRows' => $job->totalRows], 'import');

            return $job;
        });
    }

    public function append(string $actorId, string $jobId, string $content): ImportJob
    {
        return $this->transactions->run(function () use ($actorId, $jobId, $content) {
            $job = $this->imports->findJob($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ($job->status !== ImportJobStatus::DRAFT) {
                throw new BusinessRule('IMPORT_LOCKED', 'Batch hanya dapat ditambah sebelum validasi.', 409);
            }
            if (strlen($content) > config('dmx.import_max_mb', 20) * 1024 * 1024) {
                throw new BusinessRule('PAYLOAD_TOO_LARGE', 'Ukuran file melebihi batas import.', 413);
            }
            [, $rows] = CsvParser::parse($content);
            $base = $job->totalRows;
            foreach ($rows as $i => $row) {
                $rows[$i]['rowNumber'] = $base + $row['rowNumber'];
            }
            if ($job->totalRows + count($rows) > config('dmx.import_max_rows', 100000)) {
                throw new BusinessRule('TOO_MANY_ROWS', 'Jumlah baris melebihi batas import.', 422);
            }
            $this->imports->insertRows($job, $rows);
            $job->totalRows += count($rows);
            $this->imports->saveJob($job);
            $this->audit->record('import.appended', $actorId, $job->id, [], ['addedRows' => count($rows)], 'import');

            return $job;
        });
    }

    public function validateNow(string $actorId, string $jobId): ImportJob
    {
        $job = $this->imports->findJob($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if (! in_array($job->status, [ImportJobStatus::DRAFT, ImportJobStatus::READY], true)) {
            throw new BusinessRule('IMPORT_LOCKED', 'Job tidak dalam status yang dapat divalidasi.', 409);
        }
        $job->status = ImportJobStatus::VALIDATING;
        $this->imports->saveJob($job);
        $page = 1;
        do {
            $chunk = $this->imports->page($jobId, null, $page, 1000);
            foreach ($chunk['rows'] as $row) {
                if ($row->status === ImportRowStatus::IMPORTED || $row->status === ImportRowStatus::SKIPPED) {
                    continue;
                }
                ['normalized' => $n, 'status' => $status, 'codes' => $codes] = $this->normalizer->normalize($row->raw);
                $row->normalized = $n;
                $row->status = $status;
                $row->codes = $codes;
                $row->result = null;
                $this->imports->saveRow($row);
            }
            $page++;
        } while (($page - 1) * 1000 < $chunk['total']);
        $this->markDuplicates($jobId);
        $this->flagCustomerChoice($jobId);
        $fresh = $this->imports->findJob($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($fresh->status !== ImportJobStatus::VALIDATING) {
            return $fresh;
        }
        $fresh->status = ImportJobStatus::READY;
        $fresh->counters = $this->reconcile($jobId);
        $this->imports->saveJob($fresh);
        $this->audit->record('import.validated', $actorId, $jobId, [], $fresh->counters, 'import');

        return $fresh;
    }

    public function review(string $actorId, string $jobId, array $items): ImportJob
    {
        return $this->transactions->run(function () use ($actorId, $jobId, $items) {
            $job = $this->imports->findJob($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ($job->status !== ImportJobStatus::READY) {
                throw new BusinessRule('IMPORT_LOCKED', 'Review hanya dapat dilakukan setelah validasi.', 409);
            }
            foreach ($items as $item) {
                $row = $this->imports->findRow($jobId, (int) ($item['rowNumber'] ?? 0), true)
                    ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
                if ((int) ($item['version'] ?? 0) !== $row->version) {
                    throw new BusinessRule('VERSION_CONFLICT', 'Data telah diubah pengguna lain. Muat ulang data sebelum menyimpan.', 409);
                }
                if ($row->status === ImportRowStatus::IMPORTED || $row->status === ImportRowStatus::SKIPPED) {
                    throw new BusinessRule('IMPORT_LOCKED', 'Baris sudah memiliki hasil akhir.', 409);
                }
                $decision = ImportDecision::tryFrom($item['decision'] ?? '')
                    ?? throw new BusinessRule('INVALID_ENUM', 'Keputusan review tidak dikenali.', 422);
                if ($row->status === ImportRowStatus::INVALID && $decision !== ImportDecision::SKIP) {
                    throw new BusinessRule('REVIEW_NOT_ALLOWED', 'Baris invalid hanya dapat dilewati.', 422);
                }
                $fields = $item['approvedFields'] ?? null;
                if ($decision === ImportDecision::MERGE_SELECTED_FIELDS) {
                    if (! is_array($fields) || $fields === [] || array_diff($fields, self::MERGEABLE) !== []) {
                        throw new BusinessRule('INVALID_MERGE_FIELDS', 'Field yang digabung tidak valid.', 422);
                    }
                } else {
                    $fields = null;
                }
                $row->decision = $decision;
                $row->approvedFields = $fields;
                if ($decision === ImportDecision::SKIP) {
                    $row->status = ImportRowStatus::SKIPPED;
                }
                $row->version++;
                $this->imports->saveRow($row);
            }
            $job->counters = $this->reconcile($jobId);
            $this->imports->saveJob($job);
            $this->audit->record('import.reviewed', $actorId, $jobId, [], ['reviewedRows' => count($items)], 'import');

            return $job;
        });
    }

    public function commitNow(string $actorId, string $jobId): ImportJob
    {
        $job = $this->imports->findJob($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if (! in_array($job->status, [ImportJobStatus::READY, ImportJobStatus::COMMITTING], true)) {
            throw new BusinessRule('IMPORT_LOCKED', 'Job tidak dalam status yang dapat di-commit.', 409);
        }
        $job->status = ImportJobStatus::COMMITTING;
        $this->imports->saveJob($job);
        $batch = config('dmx.import_batch', 250);
        $prev = $this->imports->pendingSignature($jobId);
        while ($this->imports->committableCount($jobId) > 0) {
            $rows = $this->imports->committableSlice($jobId, $batch);
            $this->transactions->run(function () use ($actorId, $rows) {
                foreach ($rows as $row) {
                    $this->commitRow($actorId, $row);
                }
            });
            $now = $this->imports->pendingSignature($jobId);
            if ($now === $prev) {
                throw new BusinessRule('IMPORT_FAILED', 'Commit tidak mengalami kemajuan. Coba lagi.', 500);
            }
            $prev = $now;
        }
        $counts = $this->reconcile($jobId);
        $fresh = $this->imports->findJob($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($fresh->status !== ImportJobStatus::COMMITTING) {
            return $fresh;
        }
        $fresh->status = $counts['pending'] === 0 ? ImportJobStatus::DONE : ImportJobStatus::READY;
        $fresh->counters = $counts;
        $this->imports->saveJob($fresh);
        $this->audit->record('import.committed', $actorId, $jobId, [], $counts, 'import');

        return $fresh;
    }

    public function cancel(string $actorId, string $jobId): ImportJob
    {
        return $this->transactions->run(function () use ($actorId, $jobId) {
            $job = $this->imports->findJob($jobId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if (! in_array($job->status, [ImportJobStatus::DRAFT, ImportJobStatus::VALIDATING, ImportJobStatus::READY], true)) {
                throw new BusinessRule('IMPORT_LOCKED', 'Job yang sudah commit tidak dapat dibatalkan.', 409);
            }
            $job->status = ImportJobStatus::CANCELLED;
            $this->imports->saveJob($job);
            $this->audit->record('import.cancelled', $actorId, $jobId, [], [], 'import');

            return $job;
        });
    }

    public function reconcile(string $jobId): array
    {
        $counts = $this->imports->counts($jobId);
        $job = $this->imports->findJob($jobId);
        $counts['totalRows'] = $job->totalRows;
        $counts['pending'] = $counts['VALID'] + $counts['WARNING'] + $counts['DUPLICATE'] + ($counts['INVALID'] - $counts['failed']);
        $counts['balanced'] = $counts['pending'] === 0;
        $counts['duplicateFile'] = $this->imports->shaExists($job->fileSha256, $job->id);

        return $counts;
    }

    public function errorsCsv(string $jobId): string
    {
        $lines = ['row_number,status,codes,result'];
        foreach ($this->imports->failures($jobId) as $row) {
            $lines[] = implode(',', [$row->rowNumber, $row->status->value,
                '"'.str_replace('"', '""', implode(';', $row->codes)).'"',
                '"'.str_replace('"', '""', $this->sanitize((string) $row->result)).'"']);
        }

        return implode("\n", $lines)."\n";
    }

    private function commitRow(string $actorId, ImportRow $row): void
    {
        $decision = $row->decision ?? ($row->status === ImportRowStatus::VALID ? ImportDecision::CREATE_NEW : ImportDecision::SKIP);
        if ($decision === ImportDecision::SKIP) {
            $row->status = ImportRowStatus::SKIPPED;
            $this->imports->saveRow($row);

            return;
        }
        try {
            $n = $row->normalized;
            $prospectId = $decision === ImportDecision::CREATE_NEW
                ? $this->createProspect($actorId, $n, in_array('CUSTOMER_CHOICE_REQUIRED', $row->codes, true))
                : $this->updateProspect($actorId, $n, $decision, $row->approvedFields ?? []);
            $row->prospectId = $prospectId;
            $row->status = ImportRowStatus::IMPORTED;
            $row->decision = $decision;
            $row->result = null;
        } catch (\Throwable $e) {
            if (! $e instanceof BusinessRule) {
                Log::error('Import commit gagal', ['jobId' => $row->jobId, 'rowNumber' => $row->rowNumber,
                    'exception' => get_class($e)]);
            }
            $row->status = ImportRowStatus::INVALID;
            $row->result = $e instanceof BusinessRule ? $e->errorCode.': '.$e->getMessage() : 'COMMIT_FAILED: gagal menyimpan baris.';
        }
        $this->imports->saveRow($row);
    }

    private function createProspect(string $actorId, array $n, bool $forceNewCustomer): string
    {
        $stage = in_array($n['stage'], [ProspectStage::WON->value, ProspectStage::LOST->value], true) ? 'FOLLOW_UP' : $n['stage'];
        $prospect = $this->prospects->create($actorId, $this->createData($n, $stage));
        if ($n['ownerUserId'] !== $prospect->ownerUserId) {
            $this->prospects->assign($actorId, $prospect->id, $n['ownerUserId']);
        }
        if ($n['stage'] === ProspectStage::WON->value) {
            $deal = $this->deals->create($actorId, $prospect->id,
                ['potentialValue' => $n['potentialValue'], 'quotationValue' => $n['quotationValue']]);
            $this->deals->won($actorId, $deal->id, ['closingDate' => $n['closingDate'], 'closingValue' => $n['closingValue']]
                + ($forceNewCustomer ? ['customerMode' => 'new'] : []));
        }
        if ($n['stage'] === ProspectStage::LOST->value) {
            $deal = $this->deals->create($actorId, $prospect->id,
                ['potentialValue' => $n['potentialValue'], 'quotationValue' => $n['quotationValue']]);
            $this->deals->lost($actorId, $deal->id, ['lostReasonCode' => $n['lostReasonCode']]);
        }

        return $prospect->id;
    }

    private function createData(array $n, string $stage): array
    {
        return ['accountName' => $n['accountName'], 'phone' => $n['phoneRaw'] ?? '', 'email' => $n['email'],
            'picName' => $n['picName'], 'picPosition' => $n['picPosition'], 'city' => $n['city'],
            'province' => $n['province'], 'sourceCode' => $n['sourceCode'], 'industryCode' => $n['industryCode'],
            'priority' => $n['priority'], 'stage' => $stage, 'entryDate' => $n['entryDate'],
            'nextFollowUpAt' => $n['nextFollowUpAt'], 'nextAction' => $n['nextAction'],
            'lastProgress' => $n['lastProgress'], 'notes' => $n['notes'], 'potentialValue' => $n['potentialValue'],
            'paymentStatus' => $n['paymentStatus'], 'customerType' => $n['customerType'],
            'legacyId' => $n['externalId'], 'legacySourceRow' => $n['legacySourceRow']];
    }

    private function updateProspect(string $actorId, array $n, ImportDecision $decision, array $fields): string
    {
        if (in_array($n['stage'], [ProspectStage::WON->value, ProspectStage::LOST->value], true)) {
            throw new BusinessRule('REVIEW_NOT_ALLOWED', 'Closing via update didukung hanya untuk entry baru.', 422);
        }
        $candidates = $this->prospectRepo->candidates($n['externalId'], $n['phoneNormalized'], $n['email'], null, null);
        $target = $candidates[0]->prospectId ?? throw new BusinessRule('NOT_FOUND', 'Kandidat update tidak ditemukan.', 422);
        $current = $this->prospectRepo->find($target)
            ?? throw new BusinessRule('NOT_FOUND', 'Kandidat update tidak ditemukan.', 422);
        $data = ['version' => $current->version];
        $map = ['accountName' => 'accountName', 'picName' => 'picName', 'picPosition' => 'picPosition',
            'phone' => 'phoneRaw', 'email' => 'email', 'city' => 'city', 'province' => 'province',
            'industryCode' => 'industryCode', 'lastProgress' => 'lastProgress', 'nextFollowUpAt' => 'nextFollowUpAt',
            'nextAction' => 'nextAction', 'potentialValue' => 'potentialValue',
            'paymentStatus' => 'paymentStatus', 'notes' => 'notes'];
        $keys = $decision === ImportDecision::MERGE_SELECTED_FIELDS ? $fields : array_keys($map);
        foreach ($keys as $key) {
            $value = $key === 'phone' ? ($n['phoneNormalized'] ? $n['phoneRaw'] : null) : ($n[$map[$key] ?? ''] ?? null);
            if ($value !== null) {
                $data[$key] = $value;
            }
        }
        $this->prospects->update($actorId, $target, $data);

        return $target;
    }

    private function markDuplicates(string $jobId): void
    {
        $keys = ['external' => [], 'phone' => [], 'email' => [], 'nameCity' => []];
        $page = 1;
        do {
            $chunk = $this->imports->page($jobId, null, $page, 1000);
            foreach ($chunk['rows'] as $row) {
                if ($row->status === ImportRowStatus::INVALID || ! $row->normalized) {
                    continue;
                }
                $n = $row->normalized;
                if ($n['externalId']) {
                    $keys['external'][$n['externalId']][] = $row->rowNumber;
                }
                if ($n['phoneNormalized']) {
                    $keys['phone'][$n['phoneNormalized']][] = $row->rowNumber;
                }
                if ($n['email']) {
                    $keys['email'][$n['email']][] = $row->rowNumber;
                }
                if ($n['accountName'] && $n['city']) {
                    $keys['nameCity'][mb_strtolower(trim($n['accountName'])).'|'.mb_strtolower(trim($n['city']))][] = $row->rowNumber;
                }
            }
            $page++;
        } while (($page - 1) * 1000 < $chunk['total']);
        $dbExternal = $this->existing('legacy_id', array_keys($keys['external']));
        $dbPhone = $this->existing('phone_normalized', array_keys($keys['phone']));
        $dbEmail = $this->existing('email', array_keys($keys['email']));
        $dbNameCity = $this->existingNameCity(array_keys($keys['nameCity']));
        $dupes = [];
        $flag = function (array $group, array $db, string $code) use (&$dupes) {
            foreach ($group as $value => $rows) {
                if (isset($db[$value]) || count($rows) > 1) {
                    foreach ($rows as $rowNumber) {
                        $dupes[$rowNumber] ??= $code;
                    }
                }
            }
        };
        $flag($keys['external'], $dbExternal, 'DUPLICATE_EXTERNAL_ID');
        $flag($keys['phone'], $dbPhone, 'DUPLICATE_PHONE');
        $flag($keys['email'], $dbEmail, 'DUPLICATE_EMAIL');
        $flag($keys['nameCity'], $dbNameCity, 'POSSIBLE_DUPLICATE_NAME_CITY');
        foreach ($dupes as $rowNumber => $code) {
            $row = $this->imports->findRow($jobId, $rowNumber);
            if ($row && $row->status !== ImportRowStatus::INVALID) {
                $row->status = ImportRowStatus::DUPLICATE;
                $row->codes = array_values(array_unique([$code, ...$row->codes]));
                $this->imports->saveRow($row);
            }
        }
    }

    private function flagCustomerChoice(string $jobId): void
    {
        $page = 1;
        do {
            $chunk = $this->imports->page($jobId, null, $page, 1000);
            foreach ($chunk['rows'] as $row) {
                if (! $row->normalized || ($row->normalized['stage'] ?? null) !== ProspectStage::WON->value) {
                    continue;
                }
                if ($row->status !== ImportRowStatus::VALID && $row->status !== ImportRowStatus::WARNING) {
                    continue;
                }
                $n = $row->normalized;
                if (! $n['phoneNormalized'] && ! $n['email']) {
                    continue;
                }
                $match = DB::table('customers')
                    ->when($n['phoneNormalized'], fn ($q) => $q->orWhere('phone_normalized', $n['phoneNormalized']))
                    ->when($n['email'], fn ($q) => $q->orWhere('email', $n['email']))
                    ->exists();
                if ($match) {
                    $row->status = ImportRowStatus::DUPLICATE;
                    $row->codes = array_values(array_unique([...$row->codes, 'CUSTOMER_CHOICE_REQUIRED']));
                    $this->imports->saveRow($row);
                }
            }
            $page++;
        } while (($page - 1) * 1000 < $chunk['total']);
    }

    private function existing(string $column, array $values): array
    {
        $found = [];
        foreach (array_chunk($values, 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            foreach (DB::table('prospects')->whereIn($column, $chunk)->pluck($column) as $v) {
                $found[$v] = true;
            }
        }

        return $found;
    }

    private function existingNameCity(array $pairs): array
    {
        $found = [];
        $names = [];
        foreach ($pairs as $pair) {
            [$name] = explode('|', $pair, 2);
            $names[$name] = true;
        }
        foreach (array_chunk(array_keys($names), 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $rows = DB::table('prospects')->select(['account_name', 'city'])
                ->whereRaw('lower(account_name) in ('.implode(',', array_fill(0, count($chunk), '?')).')', $chunk)->get();
            foreach ($rows as $r) {
                if ($r->city !== null) {
                    $found[mb_strtolower(trim($r->account_name)).'|'.mb_strtolower(trim($r->city))] = true;
                }
            }
        }

        return $found;
    }

    private function sanitize(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
