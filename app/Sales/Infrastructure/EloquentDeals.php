<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\Deal;
use App\Sales\Domain\DealRepository;
use App\Sales\Domain\DealStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentDeals implements DealRepository
{
    public function find(string $id, bool $lock = false): ?Deal
    {
        return $this->map(DealRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id));
    }

    public function openFor(string $prospectId, bool $lock = false): ?Deal
    {
        return $this->map(DealRecord::query()->where('prospect_id', $prospectId)->where('status', 'OPEN')
            ->when($lock, fn ($q) => $q->lockForUpdate())->first());
    }

    public function map(?DealRecord $record): ?Deal
    {
        return $record ? new Deal($record->id, $record->prospect_id, DealStatus::from($record->status),
            $record->customer_id, $record->deal_number, $record->quotation_number,
            $record->potential_value !== null ? (string) $record->potential_value : null,
            $record->quotation_value !== null ? (string) $record->quotation_value : null,
            $record->closing_value !== null ? (string) $record->closing_value : null,
            $record->closing_date?->toDateString(), $record->lost_reason_code, $record->payment_status,
            $record->closing_owner_id, $record->version) : null;
    }

    public function save(Deal $deal): void
    {
        $record = DealRecord::find($deal->id) ?? new DealRecord;
        $record->id = $deal->id;
        $record->forceFill(['prospect_id' => $deal->prospectId, 'customer_id' => $deal->customerId,
            'deal_number' => $deal->dealNumber ?? $record->deal_number ?? $this->nextDealNumber(),
            'quotation_number' => $deal->quotationNumber, 'potential_value' => $deal->potentialValue,
            'quotation_value' => $deal->quotationValue, 'closing_value' => $deal->closingValue,
            'closing_date' => $deal->closingDate, 'status' => $deal->status->value,
            'lost_reason_code' => $deal->lostReasonCode, 'payment_status' => $deal->paymentStatus,
            'closing_owner_id' => $deal->closingOwnerId, 'version' => $deal->version])->save();
        $deal->dealNumber = $record->deal_number;
    }

    public function history(string $dealId, ?string $from, string $to, string $actorId): void
    {
        DB::table('payment_status_histories')->insert(['id' => (string) Str::uuid(),
            'deal_id' => $dealId, 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $actorId, 'created_at' => now()]);
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }

    public function nextDealNumber(): string
    {
        return 'DL-'.Str::upper(Str::random(8));
    }
}
