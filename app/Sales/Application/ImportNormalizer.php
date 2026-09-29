<?php

namespace App\Sales\Application;

use App\Identity\Domain\AccountRepository;
use App\Sales\Domain\ImportRowStatus;
use App\Sales\Domain\PhoneNumber;
use App\Sales\Domain\ProspectRepository;

final class ImportNormalizer
{
    private const ALIASES = [
        'id' => 'external_id', 'externalid' => 'external_id',
        'tanggalmasuk' => 'entry_date', 'entrydate' => 'entry_date',
        'namaaccountcustomer' => 'account_name', 'accountname' => 'account_name', 'account' => 'account_name',
        'pic' => 'pic_name', 'picname' => 'pic_name',
        'jabatan' => 'pic_position', 'picposition' => 'pic_position',
        'nowhatsapphandphone' => 'phone', 'phone' => 'phone', 'whatsapp' => 'phone',
        'handphone' => 'phone', 'telepon' => 'phone', 'no' => 'phone',
        'email' => 'email', 'kota' => 'city', 'city' => 'city',
        'provinsi' => 'province', 'province' => 'province',
        'industry' => 'industry', 'industri' => 'industry',
        'sumberprospek' => 'prospect_source', 'prospectsource' => 'prospect_source', 'sumber' => 'prospect_source',
        'ownerpicinternal' => 'owner_username', 'ownerusername' => 'owner_username',
        'owner' => 'owner_username', 'picinternal' => 'owner_username',
        'stage' => 'stage', 'status' => 'status', 'priority' => 'priority',
        'progresterakhir' => 'last_progress', 'lastprogress' => 'last_progress',
        'nextfollowup' => 'next_follow_up_at', 'nextfollowupat' => 'next_follow_up_at',
        'nextaction' => 'next_action',
        'potentialvalue' => 'potential_value',
        'quotationvalue' => 'quotation_value',
        'closingdate' => 'closing_date', 'closingvalue' => 'closing_value',
        'lostreason' => 'lost_reason', 'alasanlost' => 'lost_reason',
        'paymentstatus' => 'payment_status',
        'customertypenewentryonly' => 'customer_type', 'customertype' => 'customer_type',
        'catatan' => 'notes', 'notes' => 'notes',
        'legacysourcerow' => 'legacy_source_row',
    ];

    private const STAGES = [
        'new' => 'NEW', 'followup' => 'FOLLOW_UP', 'opportunity' => 'OPPORTUNITY', 'peluang' => 'OPPORTUNITY',
        'quotation' => 'QUOTATION', 'quote' => 'QUOTATION', 'penawaran' => 'QUOTATION',
        'negotiation' => 'NEGOTIATION', 'negosiasi' => 'NEGOTIATION',
        'won' => 'WON', 'menang' => 'WON', 'lost' => 'LOST', 'kalah' => 'LOST',
        'existing' => 'MAINTENANCE', 'maintenance' => 'MAINTENANCE',
    ];

    private const PRIORITIES = ['hot' => 'HOT', 'warm' => 'WARM', 'cold' => 'COLD'];

    private const PAYMENTS = [
        'belumditagih' => 'BELUM_DITAGIH', 'invoice' => 'INVOICE',
        'tertagih' => 'TERTAGIH', 'lunas' => 'LUNAS', 'overdue' => 'OVERDUE',
    ];

    private const DATE_FORMATS = ['Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'];

    private ?array $sources = null;

    private ?array $industries = null;

    private ?array $reasons = null;

    private array $owners = [];

    public function __construct(private ProspectRepository $prospects, private AccountRepository $accounts, private int $minYear = 2000) {}

    public function normalize(array $raw): array
    {
        $in = [];
        foreach ($raw as $k => $v) {
            $key = (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $k)));
            if (isset(self::ALIASES[$key])) {
                $in[self::ALIASES[$key]] = is_string($v) ? trim($v) : $v;
            }
        }
        $errors = [];
        $warnings = [];
        $n = [];

        $n['externalId'] = $this->str($in, 'external_id');
        $account = $this->str($in, 'account_name');
        if ($account === null) {
            $errors[] = 'MISSING_REQUIRED_FIELD';
        }
        $n['accountName'] = $account;
        $n['picName'] = $this->str($in, 'pic_name');
        $n['picPosition'] = $this->str($in, 'pic_position');

        $phoneRaw = $this->str($in, 'phone');
        $n['phoneRaw'] = $phoneRaw;
        $n['phoneNormalized'] = $phoneRaw ? PhoneNumber::normalize($phoneRaw) : null;
        if ($phoneRaw === null) {
            if ($account !== null) {
                $warnings[] = 'PHONE_NOT_NORMALIZED';
            } else {
                $errors[] = 'MISSING_REQUIRED_FIELD';
            }
        } elseif ($n['phoneNormalized'] === null) {
            $warnings[] = 'PHONE_NOT_NORMALIZED';
        }

        $email = $this->str($in, 'email');
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $warnings[] = 'INVALID_ENUM';
        }
        $n['email'] = $email ? strtolower($email) : null;
        $n['city'] = $this->str($in, 'city');
        $n['province'] = $this->str($in, 'province');

        $industry = $this->resolveMaster($this->industries('industries'), $this->str($in, 'industry'));
        if ($this->str($in, 'industry') !== null && $industry === null) {
            $warnings[] = 'UNKNOWN_MASTER_VALUE';
        }
        $n['industryCode'] = $industry;

        $source = $this->resolveMaster($this->sources(), $this->str($in, 'prospect_source'));
        if ($this->str($in, 'prospect_source') === null) {
            $errors[] = 'MISSING_REQUIRED_FIELD';
        } elseif ($source === null) {
            $errors[] = 'UNKNOWN_MASTER_VALUE';
        }
        $n['sourceCode'] = $source;

        $ownerName = $this->str($in, 'owner_username');
        $owner = $ownerName ? ($this->owners[$ownerName] ??= $this->findOwner($ownerName)) : null;
        if ($ownerName === null) {
            $errors[] = 'MISSING_REQUIRED_FIELD';
        } elseif ($owner === null) {
            $errors[] = 'OWNER_NOT_FOUND';
        }
        $n['ownerUserId'] = $owner?->id;
        $n['ownerUsername'] = $ownerName;

        [$stage, $priority, $stageWarn] = $this->resolveStagePriority($in);
        $warnings = [...$warnings, ...$stageWarn];
        if ($priority === null) {
            $errors[] = 'MISSING_REQUIRED_FIELD';
        }
        $n['stage'] = $stage;
        $n['priority'] = $priority;
        $n['legacyStatus'] = $this->str($in, 'status');

        $entryDate = $this->parseDate($this->str($in, 'entry_date'), false);
        if ($this->str($in, 'entry_date') !== null && $entryDate === null) {
            $errors[] = $this->dateOutOfRange($this->str($in, 'entry_date')) ? 'INVALID_DATE_RANGE' : 'INVALID_DATE';
        }
        $n['entryDate'] = $entryDate;

        $followUp = $this->parseDate($this->str($in, 'next_follow_up_at'), true);
        if ($this->str($in, 'next_follow_up_at') !== null && $followUp === null) {
            $warnings[] = 'INVALID_DATE';
        }
        $n['nextFollowUpAt'] = $followUp;
        $n['lastProgress'] = $this->str($in, 'last_progress');
        $n['nextAction'] = $this->str($in, 'next_action');
        $n['notes'] = $this->str($in, 'notes');

        foreach (['potentialValue' => 'potential_value', 'quotationValue' => 'quotation_value', 'closingValue' => 'closing_value'] as $out => $key) {
            $parsed = $this->parseDecimal($this->str($in, $key));
            if ($this->str($in, $key) !== null && $parsed === null) {
                $errors[] = 'INVALID_DECIMAL';
            }
            $n[$out] = $parsed;
        }

        $payment = $this->str($in, 'payment_status');
        if ($payment !== null) {
            $flat = (string) preg_replace('/[^a-z]/', '', strtolower($payment));
            $mapped = self::PAYMENTS[$flat] ?? (in_array(strtoupper($payment), ['BELUM_DITAGIH', 'INVOICE', 'TERTAGIH', 'LUNAS', 'OVERDUE'], true) ? strtoupper($payment) : null);
            if ($mapped === null) {
                $warnings[] = 'UNKNOWN_MASTER_VALUE';
            }
            $n['paymentStatus'] = $mapped;
        } else {
            $n['paymentStatus'] = null;
        }

        $type = $this->str($in, 'customer_type');
        if ($type !== null && ! in_array(strtoupper($type), ['B2B', 'B2C'], true)) {
            $errors[] = 'INVALID_ENUM';
            $n['customerType'] = null;
        } else {
            $n['customerType'] = $type ? strtoupper($type) : null;
        }

        $n['closingDate'] = $this->parseDate($this->str($in, 'closing_date'), false);
        if ($this->str($in, 'closing_date') !== null && $n['closingDate'] === null) {
            $errors[] = $this->dateOutOfRange($this->str($in, 'closing_date')) ? 'INVALID_DATE_RANGE' : 'INVALID_DATE';
        }
        $reason = $this->resolveMaster($this->reasons(), $this->str($in, 'lost_reason'));
        if ($this->str($in, 'lost_reason') !== null && $reason === null) {
            $errors[] = 'UNKNOWN_MASTER_VALUE';
        }
        $n['lostReasonCode'] = $reason;

        if ($stage === 'WON' && ($n['closingDate'] === null || $n['closingValue'] === null)) {
            $errors[] = 'MISSING_REQUIRED_FIELD';
        }
        if ($stage === 'LOST' && $reason === null) {
            $errors[] = 'MISSING_REQUIRED_FIELD';
        }

        $legacyRow = $this->str($in, 'legacy_source_row');
        $n['legacySourceRow'] = $legacyRow !== null && ctype_digit($legacyRow) ? (int) $legacyRow : null;

        $status = $errors !== [] ? ImportRowStatus::INVALID
            : ($warnings !== [] ? ImportRowStatus::WARNING : ImportRowStatus::VALID);

        return ['normalized' => $n, 'status' => $status, 'codes' => [...$errors, ...$warnings]];
    }

    private function resolveStagePriority(array $in): array
    {
        $warnings = [];
        $flat = fn (?string $v) => $v === null ? null : (string) preg_replace('/[^a-z]/', '', strtolower($v));
        $stageRaw = $flat($this->str($in, 'stage'));
        $priorityRaw = $flat($this->str($in, 'priority'));
        $legacy = $flat($this->str($in, 'status'));
        $priority = ($priorityRaw ? (self::PRIORITIES[$priorityRaw] ?? null) : null)
            ?? ($stageRaw ? (self::PRIORITIES[$stageRaw] ?? null) : null)
            ?? ($legacy ? (self::PRIORITIES[$legacy] ?? null) : null);
        $stage = ($stageRaw ? (self::STAGES[$stageRaw] ?? null) : null)
            ?? ($stageRaw === 'closing' ? 'CLOSING' : null);
        if ($stage === null && $legacy && isset(self::STAGES[$legacy])
            && ! in_array($legacy, ['closing', 'cancel', 'cancelled', 'batal'], true)) {
            $stage = self::STAGES[$legacy];
        }
        if ($stage === null) {
            $stage = 'FOLLOW_UP';
            if ($legacy !== null || $priority !== null) {
                $warnings[] = 'LEGACY_STAGE_INFERRED';
            }
        }

        return [$stage, $priority, $warnings];
    }

    private function parseDate(?string $value, bool $time): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $serial = (int) $value;
            if ($serial < 20000 || $serial > 80000) {
                return null;
            }
            $date = (new \DateTimeImmutable('1899-12-30'))->modify("+$serial days");

            return $this->inRange($date) ? $date->format($time ? 'Y-m-d H:i:s' : 'Y-m-d') : null;
        }
        foreach (self::DATE_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed && $parsed->format($format) === $value && $this->inRange($parsed)) {
                return $parsed->format($time ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
        }

        return null;
    }

    private function dateOutOfRange(string $value): bool
    {
        if (ctype_digit($value)) {
            $serial = (int) $value;

            return $serial >= 20000 && $serial <= 80000
                && ! $this->inRange((new \DateTimeImmutable('1899-12-30'))->modify("+$serial days"));
        }
        foreach (self::DATE_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed && $parsed->format($format) === $value) {
                return ! $this->inRange($parsed);
            }
        }

        return false;
    }

    private function inRange(\DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');

        return $year >= $this->minYear && $year <= ((int) date('Y') + 1);
    }

    private function parseDecimal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = (string) preg_replace('/[^0-9,.\-]/', '', $value);
        if ($clean === '' || substr_count($clean, '-') > 1 || ($clean !== '' && str_contains($clean, '-') && ! str_starts_with($clean, '-'))) {
            return null;
        }
        $dots = substr_count($clean, '.');
        $commas = substr_count($clean, ',');
        if ($dots > 0 && $commas > 0) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif ($commas > 0) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif ($dots > 1) {
            $clean = str_replace('.', '', $clean);
        }
        if (! is_numeric($clean) || (float) $clean < 0) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }

    private function resolveMaster(array $masters, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        foreach ($masters as $m) {
            $code = is_array($m) ? $m['code'] : $m->code;
            $label = is_array($m) ? $m['label'] : $m->label;
            if (strcasecmp($code, $value) === 0 || strcasecmp($label, $value) === 0) {
                return $code;
            }
        }

        return null;
    }

    private function sources(): array
    {
        return $this->sources ??= $this->prospects->masters('prospect_sources');
    }

    private function industries(string $table): array
    {
        return $this->industries ??= $this->prospects->masters($table);
    }

    private function reasons(): array
    {
        return $this->reasons ??= $this->prospects->masters('lost_reasons');
    }

    private function findOwner(string $username): ?object
    {
        $account = $this->accounts->byLogin($username);

        return $account && $account->active ? $account : null;
    }

    private function str(array $in, string $key): ?string
    {
        $v = $in[$key] ?? null;
        $v = is_string($v) ? trim($v) : null;

        return $v === '' ? null : $v;
    }
}
