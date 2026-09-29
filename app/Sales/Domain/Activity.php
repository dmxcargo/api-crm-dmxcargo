<?php

namespace App\Sales\Domain;

use App\Shared\Domain\BusinessRule;

final class Activity
{
    public function __construct(
        public string $id,
        public string $prospectId,
        public ActivityType $type,
        public string $actorUserId,
        public string $ownerUserId,
        public ?string $activityAt = null,
        public ?bool $answered = null,
        public ?int $durationMinutes = null,
        public ?AttendanceStatus $attendance = null,
        public ?CompletionStatus $completion = null,
        public ?string $notes = null,
    ) {
        if ($durationMinutes !== null && $durationMinutes < 0) {
            throw new BusinessRule('INVALID_DURATION', 'Durasi tidak boleh negatif.', 422);
        }
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'prospectId' => $this->prospectId, 'type' => $this->type->value,
            'actorUserId' => $this->actorUserId, 'ownerUserId' => $this->ownerUserId, 'activityAt' => $this->activityAt,
            'answered' => $this->answered, 'durationMinutes' => $this->durationMinutes,
            'attendance' => $this->attendance?->value, 'completion' => $this->completion?->value, 'notes' => $this->notes];
    }
}
