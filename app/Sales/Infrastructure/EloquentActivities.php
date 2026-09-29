<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\Activity;
use App\Sales\Domain\ActivityRepository;
use App\Sales\Domain\ActivityType;
use App\Sales\Domain\AttendanceStatus;
use App\Sales\Domain\CompletionStatus;

final class EloquentActivities implements ActivityRepository
{
    public function save(Activity $activity): void
    {
        $record = new ActivityRecord;
        $record->id = $activity->id;
        $record->forceFill(['prospect_id' => $activity->prospectId, 'type' => $activity->type->value,
            'actor_user_id' => $activity->actorUserId, 'owner_user_id' => $activity->ownerUserId,
            'activity_at' => $activity->activityAt ?? now(), 'answered' => $activity->answered,
            'duration_minutes' => $activity->durationMinutes, 'attendance_status' => $activity->attendance?->value,
            'completion_status' => $activity->completion?->value, 'notes' => $activity->notes,
            'created_at' => now()])->save();
    }

    public function map(ActivityRecord $record): Activity
    {
        return new Activity($record->id, $record->prospect_id, ActivityType::from($record->type),
            $record->actor_user_id, $record->owner_user_id, $record->activity_at->toISOString(),
            $record->answered, $record->duration_minutes,
            $record->attendance_status ? AttendanceStatus::from($record->attendance_status) : null,
            $record->completion_status ? CompletionStatus::from($record->completion_status) : null, $record->notes);
    }
}
