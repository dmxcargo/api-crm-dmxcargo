<?php

namespace App\Identity\Application;

use App\Audit\Application\AuditWriter;
use App\Identity\Domain\Account;
use App\Identity\Domain\AccountRepository;
use App\Identity\Domain\Role;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\IdempotencyRecord;
use App\Shared\Domain\IdempotencyStore;
use App\Shared\Domain\UnitOfWork;

final class ManageAccounts
{
    public function __construct(private AccountRepository $accounts, private UnitOfWork $transactions,
        private PasswordHasher $passwords, private Tokens $tokens, private AuditWriter $audit, private IdempotencyStore $keys) {}

    public function create(string $actorId, array $data, ?string $idempotencyKey = null): Account
    {
        return $this->transactions->run(function () use ($actorId, $data, $idempotencyKey) {
            $this->accounts->lockAdministration();
            $this->authorize($actorId);
            $hash = $idempotencyKey ? IdempotencyRecord::hash($data) : null;
            if ($idempotencyKey && $hash) {
                $hit = $this->keys->find($idempotencyKey, $actorId, 'users.store');
                if ($hit && $hit->payloadHash !== $hash) {
                    throw new BusinessRule('IDEMPOTENCY_CONFLICT', 'Idempotency-Key sudah dipakai dengan data berbeda. Gunakan kunci baru.', 409);
                }
                if ($hit?->resourceId && $existing = $this->accounts->find($hit->resourceId)) {
                    return $existing;
                }
            }
            $account = new Account($this->accounts->nextId(), $data['name'], $data['username'], $data['email'],
                $this->passwords->hash($data['password']), Role::from($data['role']));
            $this->accounts->save($account);
            $this->audit->record('user.created', $actorId, $account->id, [], $account->auditData());
            if ($idempotencyKey && $hash) {
                $this->keys->save(new IdempotencyRecord($idempotencyKey, $actorId, 'users.store', $hash, $account->id),
                    max(1, config('dmx.idempotency_ttl_hours')));
            }

            return $account;
        });
    }

    public function update(string $actorId, string $id, array $data): Account
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            // Kunci dulu: cegah dua demosi admin-terakhir yang bersamaan.
            $this->accounts->lockAdministration();
            $this->authorize($actorId);
            $account = $this->accounts->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $before = $account->auditData();
            $oldRole = $account->role;
            $account->revise($data, $this->accounts->activeAdmins());
            if (isset($data['password'])) {
                $account->passwordHash = $this->passwords->hash($data['password']);
            }
            $this->accounts->save($account);
            if (! $account->active || $oldRole !== $account->role || isset($data['password'])) {
                $this->tokens->revokeAll($account->id);
            }
            $this->audit->record('user.updated', $actorId, $id, $before, [...$account->auditData(), 'changedFields' => array_values(array_diff(array_keys($data), ['version']))]);

            return $account;
        });
    }

    public function revoke(string $actorId, string $id): void
    {
        $this->transactions->run(function () use ($actorId, $id) {
            $this->accounts->lockAdministration();
            $this->authorize($actorId);
            $this->accounts->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $this->tokens->revokeAll($id);
            $this->audit->record('user.tokens_revoked', $actorId, $id);
        });
    }

    private function authorize(string $actorId): void
    {
        $actor = $this->accounts->find($actorId);
        if (! $actor?->active || ! $actor->role->allows('users.manage')) {
            throw new BusinessRule('FORBIDDEN', 'Anda tidak memiliki izin untuk tindakan ini.', 403);
        }
    }
}
