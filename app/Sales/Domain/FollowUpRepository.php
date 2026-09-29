<?php

namespace App\Sales\Domain;

interface FollowUpRepository
{
    public function find(string $id, bool $lock = false): ?FollowUp;

    public function save(FollowUp $followUp): void;

    public function nextId(): string;

    /**
     * Jadwal paling awal dari follow-up yang masih terbuka milik prospek,
     * dalam ISO-8601. Null bila tidak ada yang terbuka. Satu-satunya
     * sumber kebenaran untuk kolom prospects.next_follow_up_at.
     */
    public function nextOpenScheduledAt(string $prospectId): ?string;
}
