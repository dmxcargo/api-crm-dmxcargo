<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Sales\Domain\Customer;
use App\Sales\Domain\CustomerRepository;
use App\Sales\Domain\Deal;
use App\Sales\Domain\DealRepository;
use App\Sales\Domain\DealStatus;
use App\Sales\Domain\EventOutbox;
use App\Sales\Domain\IntegrationEvent;
use App\Sales\Domain\ProspectRepository;
use App\Sales\Domain\ProspectStage;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;

final class ManageDeals
{
    public function __construct(private ProspectRepository $prospects, private DealRepository $deals,
        private CustomerRepository $customers, private PipelineService $pipeline,
        private UnitOfWork $transactions, private AuditWriter $audit, private EventOutbox $outbox) {}

    public function create(string $actorId, string $prospectId, array $data): Deal
    {
        return $this->transactions->run(function () use ($actorId, $prospectId, $data) {
            $prospect = $this->prospects->find($prospectId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ($this->deals->openFor($prospectId, true)) {
                throw new BusinessRule('DEAL_EXISTS', 'Prospect sudah memiliki deal aktif.', 409);
            }
            $deal = new Deal($this->deals->nextId(), $prospectId, quotationNumber: $data['quotationNumber'] ?? null,
                potentialValue: $data['potentialValue'] ?? $prospect->potentialValue,
                quotationValue: $data['quotationValue'] ?? null, paymentStatus: $data['paymentStatus'] ?? null);
            $this->deals->save($deal);
            $this->prospects->save($prospect, $actorId);
            $this->audit->record('deal.created', $actorId, $deal->id, [], ['prospectId' => $prospectId], 'deal');

            return $deal;
        });
    }

    public function update(string $actorId, string $id, array $data): Deal
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            $deal = $this->deals->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $before = ['quotationValue' => $deal->quotationValue, 'paymentStatus' => $deal->paymentStatus];
            $deal->revise($data);
            $this->deals->save($deal);
            $this->audit->record('deal.updated', $actorId, $id, $before,
                ['quotationValue' => $deal->quotationValue, 'paymentStatus' => $deal->paymentStatus], 'deal');

            return $deal;
        });
    }

    public function won(string $actorId, string $id, array $data): Deal
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            $deal = $this->deals->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ($deal->status === DealStatus::WON && (float) $deal->closingValue === (float) $data['closingValue'] && $deal->closingDate === $data['closingDate']) {
                return $deal;
            }
            $deal->markWon($data['closingDate'], $data['closingValue']);
            $deal->closingOwnerId ??= $this->prospects->find($deal->prospectId, true)?->ownerUserId;
            $customerCreated = false;
            if (isset($data['customerId'])) {
                $customer = $this->customers->find($data['customerId'], true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
                $deal->customerId = $customer->id;
            } elseif (($data['customerMode'] ?? null) === 'new') {
                $customer = $this->buildCustomer($deal);
                $this->customers->save($customer);
                $deal->customerId = $customer->id;
                $customerCreated = true;
            } else {
                $candidates = $this->candidatesFor($deal);
                if ($candidates !== []) {
                    throw new BusinessRule('CUSTOMER_CHOICE_REQUIRED', 'Ditemukan '.count($candidates).' kandidat customer. Pilih customer existing atau buat baru.', 409);
                }
                $customer = $this->buildCustomer($deal);
                $this->customers->save($customer);
                $deal->customerId = $customer->id;
                $customerCreated = true;
            }
            $this->deals->save($deal);
            $this->pipeline->decide($actorId, $deal->prospectId, ProspectStage::WON);
            // Deal Won tanpa status bayar = piutang baru. Default ke BELUM_DITAGIH
            // agar dashboard billing langsung menghitungnya (bukan "Tanpa Status").
            // Hanya mengisi yang masih null agar status yang sudah diatur manual tidak tertimpa.
            if ($deal->paymentStatus === null) {
                $deal->paymentStatus = 'BELUM_DITAGIH';
                $deal->version++;
                $this->deals->save($deal);
                $prospect = $this->prospects->find($deal->prospectId, true);
                if ($prospect && $prospect->paymentStatus === null) {
                    $prospect->paymentStatus = 'BELUM_DITAGIH';
                    $this->prospects->save($prospect, $actorId);
                }
            }
            $this->audit->record('deal.won', $actorId, $id,
                [], ['closingValue' => $deal->closingValue, 'customerId' => $deal->customerId,
                    'closingOwnerId' => $deal->closingOwnerId], 'deal');
            $this->outbox->publish(new IntegrationEvent('sales.deal.won', 'deal', $id, $actorId, ['customerId' => $deal->customerId]));
            if ($customerCreated) {
                $this->outbox->publish(new IntegrationEvent('sales.customer.created', 'customer', $deal->customerId, $actorId));
            }

            return $deal;
        });
    }

    public function lost(string $actorId, string $id, array $data): Deal
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            $deal = $this->deals->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ($deal->status === DealStatus::LOST && $deal->lostReasonCode === $data['lostReasonCode']) {
                return $deal;
            }
            if (! $this->prospects->masterActive('lost_reasons', $data['lostReasonCode'])) {
                throw new BusinessRule('UNKNOWN_MASTER_VALUE', 'Alasan lost tidak dikenali.', 422);
            }
            $deal->markLost($data['lostReasonCode']);
            $deal->closingOwnerId ??= $this->prospects->find($deal->prospectId, true)?->ownerUserId;
            $this->deals->save($deal);
            $this->pipeline->decide($actorId, $deal->prospectId, ProspectStage::LOST);
            $this->audit->record('deal.lost', $actorId, $id, [],
                ['lostReasonCode' => $deal->lostReasonCode, 'closingOwnerId' => $deal->closingOwnerId], 'deal');

            return $deal;
        });
    }

    /** @return Customer[] */
    public function customerCandidates(string $dealId): array
    {
        $deal = $this->deals->find($dealId) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);

        return $this->candidatesFor($deal);
    }

    private function buildCustomer(Deal $deal): Customer
    {
        $prospect = $this->prospects->find($deal->prospectId) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);

        return new Customer($this->customers->nextId(), $prospect->accountName,
            $prospect->phone->raw, $prospect->phone->normalized, $prospect->email,
            $prospect->city, $prospect->province, $prospect->industryCode);
    }

    private function candidatesFor(Deal $deal): array
    {
        $prospect = $this->prospects->find($deal->prospectId) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);

        return $this->customers->candidates($prospect->phone->normalized, $prospect->email, $prospect->accountName);
    }
}
