<?php

namespace App\Sales\Domain;

use App\Shared\Domain\BusinessRule;

final class FollowUp
{
    public function __construct(
        public string $id,
        public string $prospectId,
        public string $assigneeUserId,
        public string $creatorUserId,
        public string $scheduledAt,
        public TaskPriority $priority = TaskPriority::SEDANG,
        public TaskStatus $status = TaskStatus::BELUM_DIMULAI,
        public string $description = '',
        public ?string $notes = null,
        public ?string $completedAt = null,
        public int $version = 1,
    ) {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 500) {
            throw new BusinessRule('INVALID_FOLLOW_UP', 'Deskripsi follow up wajib diisi dan maksimal 500 karakter.', 422);
        }
        $this->description = $description;
    }

    public function complete(): void
    {
        if ($this->status === TaskStatus::SELESAI) {
            throw new BusinessRule('ALREADY_COMPLETED', 'Follow up sudah selesai.', 409);
        }
        $this->status = TaskStatus::SELESAI;
        $this->completedAt = now()->toISOString();
        $this->version++;
    }

    public function reschedule(array $data): void
    {
        if ((int) $data['version'] !== $this->version) {
            throw new BusinessRule('VERSION_CONFLICT', 'Data telah diubah pengguna lain. Muat ulang data sebelum menyimpan.');
        }
        if ($this->status === TaskStatus::SELESAI) {
            throw new BusinessRule('ALREADY_COMPLETED', 'Follow up yang sudah selesai tidak dapat dijadwal ulang.', 409);
        }
        $this->scheduledAt = $data['scheduledAt'];
        if (isset($data['priority'])) {
            $this->priority = TaskPriority::from($data['priority']);
        }
        if (isset($data['status'])) {
            $this->status = TaskStatus::from($data['status']);
        }
        if (array_key_exists('notes', $data)) {
            $this->notes = $data['notes'];
        }
        $this->version++;
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'prospectId' => $this->prospectId, 'assigneeUserId' => $this->assigneeUserId,
            'creatorUserId' => $this->creatorUserId, 'scheduledAt' => $this->scheduledAt, 'completedAt' => $this->completedAt,
            'priority' => $this->priority->value, 'status' => $this->status->value,
            'description' => $this->description, 'notes' => $this->notes, 'version' => $this->version];
    }
}
