<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\Target;
use App\Sales\Domain\TargetPeriod;
use App\Sales\Domain\TargetRepository;
use Illuminate\Support\Str;

final class EloquentTargets implements TargetRepository
{
    public function find(string $id, bool $lock = false): ?Target
    {
        $record = TargetRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id);

        return $record ? $this->map($record) : null;
    }

    public function existing(string $userId, TargetPeriod $type, string $period): ?Target
    {
        $record = TargetRecord::where('user_id', $userId)->where('period_type', $type->value)->where('period', $period)->first();

        return $record ? $this->map($record) : null;
    }

    public function save(Target $target, string $actorId): void
    {
        $record = TargetRecord::find($target->id) ?? new TargetRecord;
        $record->id = $target->id;
        if (! $record->exists) {
            $record->created_by = $actorId;
        }
        $record->forceFill(['user_id' => $target->userId, 'period_type' => $target->periodType->value,
            'period' => $target->period, 'target_value' => $target->targetValue,
            'updated_by' => $actorId, 'version' => $target->version])->save();
    }

    public function list(?string $userId, ?string $periodType, ?string $period): array
    {
        return TargetRecord::query()->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($periodType, fn ($q) => $q->where('period_type', $periodType))
            ->when($period, fn ($q) => $q->where('period', $period))
            ->orderBy('period')->orderBy('user_id')->limit(500)->get()->map(fn ($r) => $this->map($r))->all();
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }

    private function map(TargetRecord $r): Target
    {
        return new Target($r->id, $r->user_id, TargetPeriod::from($r->period_type), $r->period, number_format((float) $r->target_value, 2, '.', ''), (int) $r->version);
    }
}
