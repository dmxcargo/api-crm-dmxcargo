<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Identity\Domain\AccountRepository;
use App\Sales\Domain\Target;
use App\Sales\Domain\TargetPeriod;
use App\Sales\Domain\TargetRepository;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;

final class ManageTargets
{
    public function __construct(private TargetRepository $targets, private AccountRepository $accounts,
        private UnitOfWork $transactions, private AuditWriter $audit) {}

    public function create(string $actorId, array $data): Target
    {
        return $this->transactions->run(function () use ($actorId, $data) {
            $type = TargetPeriod::from($data['periodType']);
            $this->checkPeriod($type, $data['period']);
            $owner = $this->accounts->find($data['userId']) ?? throw new BusinessRule('OWNER_NOT_FOUND', 'User tidak ditemukan.', 422);
            if (! $owner->active) {
                throw new BusinessRule('OWNER_NOT_FOUND', 'User tidak aktif.', 422);
            }
            if ($this->targets->existing($owner->id, $type, $data['period'])) {
                throw new BusinessRule('TARGET_EXISTS', 'Target periode tersebut sudah ada. Ubah data yang ada.', 409);
            }
            $target = new Target($this->targets->nextId(), $owner->id, $type, $data['period'], $this->money($data['targetValue']));
            $this->targets->save($target, $actorId);
            $this->audit->record('target.created', $actorId, $target->id, [], $target->publicData(), 'target');

            return $target;
        });
    }

    public function update(string $actorId, string $id, array $data): Target
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            $target = $this->targets->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ((int) $data['version'] !== $target->version) {
                throw new BusinessRule('VERSION_CONFLICT', 'Data telah diubah pengguna lain. Muat ulang data sebelum menyimpan.', 409);
            }
            $before = $target->publicData();
            $target->targetValue = $this->money($data['targetValue']);
            $target->version++;
            $this->targets->save($target, $actorId);
            $this->audit->record('target.updated', $actorId, $id, $before, $target->publicData(), 'target');

            return $target;
        });
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function checkPeriod(TargetPeriod $type, string $period): void
    {
        $valid = $type === TargetPeriod::MONTH
            ? preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)
            : preg_match('/^\d{4}$/', $period);
        if (! $valid) {
            throw new BusinessRule('INVALID_PERIOD', 'Format periode tidak valid. Gunakan YYYY-MM atau YYYY.', 422);
        }
    }
}
