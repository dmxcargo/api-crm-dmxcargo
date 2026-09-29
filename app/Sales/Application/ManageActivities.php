<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Sales\Domain\Activity;
use App\Sales\Domain\ActivityRepository;
use App\Sales\Domain\ActivityType;
use App\Sales\Domain\AttendanceStatus;
use App\Sales\Domain\CompletionStatus;
use App\Sales\Domain\FollowUp;
use App\Sales\Domain\FollowUpRepository;
use App\Sales\Domain\ProspectRepository;
use App\Sales\Domain\TaskPriority;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;

final class ManageActivities
{
    public function __construct(private ProspectRepository $prospects, private ActivityRepository $activities,
        private FollowUpRepository $followUps, private UnitOfWork $transactions, private AuditWriter $audit) {}

    public function log(string $actorId, string $prospectId, array $data): Activity
    {
        return $this->transactions->run(function () use ($actorId, $prospectId, $data) {
            $prospect = $this->prospects->find($prospectId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $activity = new Activity($this->followUps->nextId(), $prospectId, ActivityType::from($data['type']),
                $actorId, $prospect->ownerUserId, $data['activityAt'] ?? null,
                $data['answered'] ?? null, isset($data['durationMinutes']) ? (int) $data['durationMinutes'] : null,
                isset($data['attendance']) ? AttendanceStatus::from($data['attendance']) : null,
                isset($data['completion']) ? CompletionStatus::from($data['completion']) : null, $data['notes'] ?? null);
            $this->activities->save($activity);
            $patch = ['version' => $prospect->version];
            foreach (['lastProgress' => 'lastProgress', 'nextAction' => 'nextAction', 'nextFollowUpAt' => 'nextFollowUpAt'] as $in => $field) {
                if (array_key_exists($in, $data)) {
                    $patch[$field] = $data[$in];
                }
            }
            $prospect->revise($patch);
            $this->prospects->save($prospect, $actorId);
            if (isset($data['nextFollowUpAt'])) {
                $task = new FollowUp($this->followUps->nextId(), $prospectId, $prospect->ownerUserId, $actorId,
                    $data['nextFollowUpAt'], TaskPriority::from($data['taskPriority'] ?? 'SEDANG'),
                    description: $data['nextAction'] ?? $data['notes'] ?? 'Follow up');
                $this->followUps->save($task);
            }
            $this->audit->record('prospect.activity_logged', $actorId, $prospectId, [], ['type' => $activity->type->value], 'prospect');

            return $activity;
        });
    }
}
