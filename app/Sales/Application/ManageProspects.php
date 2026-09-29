<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Identity\Domain\AccountRepository;
use App\Sales\Domain\CustomerType;
use App\Sales\Domain\EventOutbox;
use App\Sales\Domain\IntegrationEvent;
use App\Sales\Domain\PhoneNumber;
use App\Sales\Domain\Prospect;
use App\Sales\Domain\ProspectContact;
use App\Sales\Domain\ProspectPriority;
use App\Sales\Domain\ProspectRepository;
use App\Sales\Domain\ProspectStage;
use App\Sales\Infrastructure\ContactRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\IdempotencyRecord;
use App\Shared\Domain\IdempotencyStore;
use App\Shared\Domain\UnitOfWork;

final class ManageProspects
{
    public function __construct(private ProspectRepository $prospects, private AccountRepository $accounts,
        private UnitOfWork $transactions, private AuditWriter $audit, private EventOutbox $outbox, private IdempotencyStore $keys,
        private PipelineService $pipeline) {}

    public function create(string $actorId, array $data, ?string $idempotencyKey = null): Prospect
    {
        return $this->transactions->run(function () use ($actorId, $data, $idempotencyKey) {
            $hash = $idempotencyKey ? IdempotencyRecord::hash($data) : null;
            if ($idempotencyKey && $hash) {
                $hit = $this->keys->find($idempotencyKey, $actorId, 'prospects.store');
                if ($hit && $hit->payloadHash !== $hash) {
                    throw new BusinessRule('IDEMPOTENCY_CONFLICT', 'Idempotency-Key sudah dipakai dengan data berbeda. Gunakan kunci baru.', 409);
                }
                if ($hit?->resourceId && $existing = $this->prospects->find($hit->resourceId)) {
                    return $existing;
                }
            }
            $this->checkMasters($data);
            $prospect = new Prospect($this->prospects->nextId(), $data['accountName'], PhoneNumber::parse($data['phone']),
                $actorId, $data['sourceCode'], ProspectPriority::from($data['priority']),
                ProspectStage::from($data['stage'] ?? 'NEW'), $data['legacyId'] ?? null,
                isset($data['legacySourceRow']) ? (int) $data['legacySourceRow'] : null, $data['entryDate'] ?? null,
                $data['picName'] ?? null, $data['picPosition'] ?? null,
                isset($data['email']) ? strtolower(trim($data['email'])) : null,
                $data['city'] ?? null, $data['province'] ?? null, $data['industryCode'] ?? null,
                $data['lastProgress'] ?? null, $data['nextFollowUpAt'] ?? null, $data['nextAction'] ?? null,
                $data['potentialValue'] ?? null, $data['paymentStatus'] ?? null,
                isset($data['customerType']) ? CustomerType::from($data['customerType']) : null, $data['notes'] ?? null);
            $this->prospects->save($prospect, $actorId);
            $this->prospects->touch($prospect->id);
            $this->audit->record('prospect.created', $actorId, $prospect->id, [], $prospect->auditData(), 'prospect');
            $this->outbox->publish(new IntegrationEvent('sales.prospect.created', 'prospect', $prospect->id, $actorId));
            if ($idempotencyKey && $hash) {
                $this->keys->save(new IdempotencyRecord($idempotencyKey, $actorId, 'prospects.store', $hash, $prospect->id),
                    max(1, config('dmx.idempotency_ttl_hours')));
            }

            return $prospect;
        });
    }

    public function update(string $actorId, string $id, array $data): Prospect
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            $prospect = $this->prospects->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $before = $prospect->auditData();
            $this->checkMasters($data);
            if (array_key_exists('phone', $data)) {
                $prospect->phone = PhoneNumber::parse($data['phone']);
                unset($data['phone']);
            }
            if (array_key_exists('sourceCode', $data)) {
                $prospect->sourceCode = $data['sourceCode'];
                unset($data['sourceCode']);
            }
            if (array_key_exists('priority', $data)) {
                $prospect->priority = ProspectPriority::from($data['priority']);
                unset($data['priority']);
            }
            $prospect->revise($data);
            $this->prospects->save($prospect, $actorId);
            $this->prospects->touch($id);
            $this->audit->record('prospect.updated', $actorId, $id, $before, [...$prospect->auditData(),
                'changedFields' => array_values(array_diff(array_keys($data), ['version']))], 'prospect');

            return $prospect;
        });
    }

    public function assign(string $actorId, string $id, string $ownerId): Prospect
    {
        return $this->transactions->run(function () use ($actorId, $id, $ownerId) {
            $prospect = $this->prospects->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $owner = $this->accounts->find($ownerId) ?? throw new BusinessRule('OWNER_NOT_FOUND', 'Owner tidak ditemukan.', 422);
            if (! $owner->active) {
                throw new BusinessRule('OWNER_NOT_FOUND', 'Owner tidak aktif.', 422);
            }
            $before = $prospect->auditData();
            $prospect->ownerUserId = $owner->id;
            $prospect->version++;
            $this->prospects->save($prospect, $actorId);
            $this->prospects->touch($id);
            $this->audit->record('prospect.assigned', $actorId, $id, $before, $prospect->auditData(), 'prospect');
            $this->outbox->publish(new IntegrationEvent('sales.owner.changed', 'prospect', $id, $actorId, ['ownerUserId' => $owner->id]));

            return $prospect;
        });
    }

    public function moveStage(string $actorId, string $id, array $data, bool $privileged): Prospect
    {
        return $this->pipeline->move($actorId, $id, $data, $privileged)[0];
    }

    public function archive(string $actorId, string $id, bool $archived): Prospect
    {
        return $this->transactions->run(function () use ($actorId, $id, $archived) {
            $prospect = $this->prospects->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $before = $prospect->auditData();
            $prospect->archived = $archived;
            $prospect->version++;
            $this->prospects->save($prospect, $actorId);
            $this->prospects->touch($id, $archived);
            $this->audit->record($archived ? 'prospect.archived' : 'prospect.unarchived', $actorId, $id, $before, $prospect->auditData(), 'prospect');

            return $prospect;
        });
    }

    public function destroy(string $actorId, string $id): void
    {
        $this->transactions->run(function () use ($actorId, $id) {
            $prospect = $this->prospects->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $this->prospects->touch($id, null, true);
            $this->audit->record('prospect.deleted', $actorId, $id, $prospect->auditData(), [], 'prospect');
        });
    }

    public function addContact(string $actorId, string $id, array $data): ProspectContact
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            $prospect = $this->prospects->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $contact = new ProspectContact($this->prospects->nextId(), $id, $data['name'], $data['position'] ?? null,
                isset($data['phone']) ? PhoneNumber::parse($data['phone'], false) : null,
                isset($data['email']) ? strtolower(trim($data['email'])) : null, (bool) ($data['primary'] ?? false));
            $record = new ContactRecord;
            $record->id = $contact->id;
            $record->forceFill(['prospect_id' => $id, 'name' => $contact->name,
                'position' => $contact->position, 'phone_raw' => $contact->phone?->raw,
                'phone_normalized' => $contact->phone?->normalized, 'email' => $contact->email, 'is_primary' => $contact->primary])->save();
            $this->prospects->save($prospect, $actorId);
            $this->audit->record('prospect.contact_added', $actorId, $id, [], ['contactId' => $contact->id], 'prospect');

            return $contact;
        });
    }

    public function removeContact(string $actorId, string $id, string $contactId): void
    {
        $this->transactions->run(function () use ($actorId, $id, $contactId) {
            $prospect = $this->prospects->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $deleted = ContactRecord::where('prospect_id', $id)->whereKey($contactId)->delete();
            if (! $deleted) {
                throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            }
            $this->prospects->save($prospect, $actorId);
            $this->audit->record('prospect.contact_removed', $actorId, $id, ['contactId' => $contactId], [], 'prospect');
        });
    }

    private function checkMasters(array $data): void
    {
        if (isset($data['sourceCode']) && ! $this->prospects->masterActive('prospect_sources', $data['sourceCode'])) {
            throw new BusinessRule('UNKNOWN_MASTER_VALUE', 'Sumber prospek tidak dikenali.', 422);
        }
        if (isset($data['industryCode']) && $data['industryCode'] !== null && ! $this->prospects->masterActive('industries', $data['industryCode'])) {
            throw new BusinessRule('UNKNOWN_MASTER_VALUE', 'Industry tidak dikenali.', 422);
        }
    }
}
