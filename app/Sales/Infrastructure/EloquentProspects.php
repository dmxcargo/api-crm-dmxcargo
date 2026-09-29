<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\CustomerType;
use App\Sales\Domain\DuplicateCandidate;
use App\Sales\Domain\PhoneNumber;
use App\Sales\Domain\Prospect;
use App\Sales\Domain\ProspectContact;
use App\Sales\Domain\ProspectPriority;
use App\Sales\Domain\ProspectRepository;
use App\Sales\Domain\ProspectStage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentProspects implements ProspectRepository
{
    public function find(string $id, bool $lock = false): ?Prospect
    {
        return $this->map(ProspectRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id));
    }

    public function map(?ProspectRecord $record): ?Prospect
    {
        if (! $record) {
            return null;
        }

        return new Prospect($record->id, $record->account_name, new PhoneNumber($record->phone_raw, $record->phone_normalized),
            $record->owner_user_id, $record->source_code, ProspectPriority::from($record->priority), ProspectStage::from($record->stage),
            $record->legacy_id, $record->legacy_source_row, $record->entry_date?->toDateString(), $record->pic_name, $record->pic_position,
            $record->email, $record->city, $record->province, $record->industry_code, $record->last_progress,
            $record->next_follow_up_at?->toISOString(), $record->next_action,
            $record->potential_value !== null ? (string) $record->potential_value : null, $record->payment_status,
            $record->customer_type ? CustomerType::from($record->customer_type) : null, $record->notes,
            $record->archived_at !== null, $record->version);
    }

    public function save(Prospect $prospect, string $actorId): void
    {
        $record = ProspectRecord::find($prospect->id) ?? new ProspectRecord;
        $record->id = $prospect->id;
        if (! $record->exists) {
            $record->created_by = $actorId;
        }
        $record->forceFill(['legacy_id' => $prospect->legacyId, 'legacy_source_row' => $prospect->legacySourceRow,
            'entry_date' => $prospect->entryDate ?? date('Y-m-d'), 'account_name' => $prospect->accountName,
            'pic_name' => $prospect->picName, 'pic_position' => $prospect->picPosition,
            'phone_raw' => $prospect->phone->raw, 'phone_normalized' => $prospect->phone->normalized,
            'email' => $prospect->email ? strtolower(trim($prospect->email)) : null,
            'city' => $prospect->city, 'province' => $prospect->province, 'industry_code' => $prospect->industryCode,
            'source_code' => $prospect->sourceCode, 'owner_user_id' => $prospect->ownerUserId,
            'stage' => $prospect->stage->value, 'priority' => $prospect->priority->value,
            'last_progress' => $prospect->lastProgress, 'next_follow_up_at' => $prospect->nextFollowUpAt,
            'next_action' => $prospect->nextAction, 'potential_value' => $prospect->potentialValue,
            'payment_status' => $prospect->paymentStatus, 'customer_type' => $prospect->customerType?->value,
            'notes' => $prospect->notes, 'version' => $prospect->version, 'updated_by' => $actorId])->save();
    }

    public function touch(string $id, ?bool $archived = null, bool $deleted = false): void
    {
        $update = [];
        if ($archived !== null) {
            $update['archived_at'] = $archived ? now() : null;
        }
        if ($deleted) {
            $update['deleted_at'] = now();
        }
        if ($update !== []) {
            ProspectRecord::whereKey($id)->update($update);
        }
    }

    public function candidates(?string $legacyId, ?string $phoneNormalized, ?string $email, ?string $accountName, ?string $city, ?string $excludeId = null): array
    {
        $found = [];
        $add = function (string $reason, ?string $value, $query) use (&$found, $excludeId) {
            if ($value === null || $value === '') {
                return;
            }
            foreach ($query->whereNull('deleted_at')->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))->limit(5)->pluck('id', 'id') as $id) {
                $found[$id] = new DuplicateCandidate($id, $reason, $value);
            }
        };
        $add('EXTERNAL_ID', $legacyId, ProspectRecord::where('legacy_id', $legacyId));
        $add('PHONE', $phoneNormalized, ProspectRecord::where('phone_normalized', $phoneNormalized));
        $add('EMAIL', $email ? strtolower(trim($email)) : null, ProspectRecord::where('email', $email ? strtolower(trim($email)) : null));
        if ($accountName && $city) {
            $add('NAME_CITY', "$accountName|$city", ProspectRecord::whereRaw('lower(account_name) = ?', [mb_strtolower(trim($accountName))])->whereRaw('lower(city) = ?', [mb_strtolower(trim($city))]));
        }

        return array_values($found);
    }

    public function masterActive(string $table, string $code): bool
    {
        return DB::table($table)->where('code', $code)->where('is_active', true)->exists();
    }

    public function masterExists(string $table, string $code): bool
    {
        return DB::table($table)->where('code', $code)->exists();
    }

    public function masters(string $table): array
    {
        return DB::table($table)->where('is_active', true)->orderBy('label')->get(['code', 'label'])->all();
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }

    public function paginate($query, int $pageSize): LengthAwarePaginator
    {
        return $query->paginate($pageSize);
    }

    public function mapContact(?ContactRecord $record): ?ProspectContact
    {
        return $record ? new ProspectContact($record->id, $record->prospect_id, $record->name, $record->position,
            $record->phone_raw ? new PhoneNumber($record->phone_raw, $record->phone_normalized) : null,
            $record->email, $record->is_primary) : null;
    }
}
