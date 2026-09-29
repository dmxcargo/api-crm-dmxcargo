<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\FollowUp;
use App\Sales\Domain\FollowUpRepository;
use App\Sales\Domain\TaskPriority;
use App\Sales\Domain\TaskStatus;
use Illuminate\Support\Str;

final class EloquentFollowUps implements FollowUpRepository
{
    public function find(string $id, bool $lock = false): ?FollowUp
    {
        return $this->map(FollowUpRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id));
    }

    public function map(?FollowUpRecord $record): ?FollowUp
    {
        return $record ? new FollowUp($record->id, $record->prospect_id, $record->assignee_user_id,
            $record->creator_user_id, $record->scheduled_at->toISOString(), TaskPriority::from($record->task_priority),
            TaskStatus::from($record->task_status), $record->description, $record->notes,
            $record->completed_at?->toISOString(), $record->version) : null;
    }

    public function save(FollowUp $followUp): void
    {
        $record = FollowUpRecord::find($followUp->id) ?? new FollowUpRecord;
        $record->id = $followUp->id;
        $record->forceFill(['prospect_id' => $followUp->prospectId, 'assignee_user_id' => $followUp->assigneeUserId,
            'creator_user_id' => $followUp->creatorUserId, 'scheduled_at' => $followUp->scheduledAt,
            'completed_at' => $followUp->completedAt, 'task_priority' => $followUp->priority->value,
            'task_status' => $followUp->status->value, 'description' => $followUp->description,
            'notes' => $followUp->notes, 'version' => $followUp->version])->save();
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }

    public function nextOpenScheduledAt(string $prospectId): ?string
    {
        $record = FollowUpRecord::query()
            ->where('prospect_id', $prospectId)
            ->whereNull('completed_at')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->first();

        return $record?->scheduled_at?->toISOString();
    }
}
