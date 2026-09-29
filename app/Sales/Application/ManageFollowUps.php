<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Identity\Domain\AccountRepository;
use App\Sales\Domain\FollowUp;
use App\Sales\Domain\FollowUpRepository;
use App\Sales\Domain\ProspectRepository;
use App\Sales\Domain\TaskPriority;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;

final class ManageFollowUps
{
    public function __construct(private ProspectRepository $prospects, private FollowUpRepository $followUps,
        private AccountRepository $accounts, private UnitOfWork $transactions, private AuditWriter $audit) {}

    public function create(string $actorId, string $prospectId, array $data): FollowUp
    {
        return $this->transactions->run(function () use ($actorId, $prospectId, $data) {
            $prospect = $this->prospects->find($prospectId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $assignee = $this->accounts->find($data['assigneeUserId'] ?? $prospect->ownerUserId)
                ?? throw new BusinessRule('OWNER_NOT_FOUND', 'Pelaksana tidak ditemukan.', 422);
            if (! $assignee->active) {
                throw new BusinessRule('OWNER_NOT_FOUND', 'Pelaksana tidak aktif.', 422);
            }
            $task = new FollowUp($this->followUps->nextId(), $prospectId, $assignee->id,
                $actorId, $data['scheduledAt'], TaskPriority::from($data['priority'] ?? 'SEDANG'),
                description: $data['description'], notes: $data['notes'] ?? null);
            $this->followUps->save($task);
            $this->refreshNextFollowUp($actorId, $prospectId);
            $this->audit->record('prospect.follow_up_created', $actorId, $prospectId, [], ['followUpId' => $task->id], 'prospect');

            return $task;
        });
    }

    public function complete(string $actorId, string $id): FollowUp
    {
        return $this->transactions->run(function () use ($actorId, $id) {
            $task = $this->followUps->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $task->complete();
            $this->followUps->save($task);
            $this->refreshNextFollowUp($actorId, $task->prospectId);
            $this->audit->record('prospect.follow_up_completed', $actorId, $task->prospectId, [], ['followUpId' => $id], 'prospect');

            return $task;
        });
    }

    public function reschedule(string $actorId, string $id, array $data): FollowUp
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            $task = $this->followUps->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $before = ['scheduledAt' => $task->scheduledAt, 'status' => $task->status->value];
            $task->reschedule($data);
            $this->followUps->save($task);
            $this->refreshNextFollowUp($actorId, $task->prospectId);
            $this->audit->record('prospect.follow_up_rescheduled', $actorId, $task->prospectId, $before,
                ['scheduledAt' => $task->scheduledAt, 'status' => $task->status->value], 'prospect');

            return $task;
        });
    }

    /**
     * Standar CRM: prospects.next_follow_up_at adalah turunan (rollup) dari
     * task terbuka — dihitung ulang di setiap mutasi task agar kolom Next FU
     * di daftar & detail selalu mencerminkan jadwal terdekat (atau kosong
     * bila tidak ada task terbuka). Tanpa bump versi agar tidak memicu
     * konflik pada editor prospek yang sedang terbuka.
     */
    private function refreshNextFollowUp(string $actorId, string $prospectId): void
    {
        $prospect = $this->prospects->find($prospectId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        $prospect->nextFollowUpAt = $this->followUps->nextOpenScheduledAt($prospectId);
        $this->prospects->save($prospect, $actorId);
    }
}
