<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Sales\Domain\Customer;
use App\Sales\Domain\CustomerRepository;
use App\Sales\Domain\Deal;
use App\Sales\Domain\DealRepository;
use App\Sales\Domain\PhoneNumber;
use App\Sales\Domain\ProspectRepository;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;

final class ManageCustomers
{
    public function __construct(private CustomerRepository $customers, private DealRepository $deals,
        private ProspectRepository $prospects, private UnitOfWork $transactions, private AuditWriter $audit) {}

    public function create(string $actorId, array $data): Customer
    {
        return $this->transactions->run(function () use ($actorId, $data) {
            $customer = new Customer($this->customers->nextId(), $data['accountName'],
                $data['phone'] ?? null, isset($data['phone']) ? PhoneNumber::normalize($data['phone']) : null,
                isset($data['email']) ? strtolower(trim($data['email'])) : null,
                $data['city'] ?? null, $data['province'] ?? null, $data['industryCode'] ?? null);
            $this->customers->save($customer);
            $this->audit->record('customer.created', $actorId, $customer->id, [], ['accountName' => $customer->accountName], 'customer');

            return $customer;
        });
    }

    public function update(string $actorId, string $id, array $data): Customer
    {
        return $this->transactions->run(function () use ($actorId, $id, $data) {
            $customer = $this->customers->find($id, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ((int) $data['version'] !== $customer->version) {
                throw new BusinessRule('VERSION_CONFLICT', 'Data telah diubah pengguna lain. Muat ulang data sebelum menyimpan.');
            }
            $before = ['accountName' => $customer->accountName, 'active' => $customer->active];
            foreach (['accountName', 'city', 'province', 'industryCode'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $customer->$field = $data[$field];
                }
            }
            if (array_key_exists('phone', $data)) {
                $customer->phoneRaw = $data['phone'];
                $customer->phoneNormalized = $data['phone'] ? PhoneNumber::normalize($data['phone']) : null;
            }
            if (array_key_exists('email', $data)) {
                $customer->email = $data['email'] ? strtolower(trim($data['email'])) : null;
            }
            if (array_key_exists('isActive', $data)) {
                $customer->active = (bool) $data['isActive'];
            }
            $customer->version++;
            $this->customers->save($customer);
            $this->audit->record('customer.updated', $actorId, $id, $before,
                ['accountName' => $customer->accountName, 'active' => $customer->active], 'customer');

            return $customer;
        });
    }

    public function repeatOrder(string $actorId, string $customerId, string $prospectId): Deal
    {
        return $this->transactions->run(function () use ($actorId, $customerId, $prospectId) {
            $this->customers->find($customerId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $this->prospects->find($prospectId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ($this->deals->openFor($prospectId, true)) {
                throw new BusinessRule('DEAL_EXISTS', 'Prospect sudah memiliki deal aktif.', 409);
            }
            $deal = new Deal($this->deals->nextId(), $prospectId, customerId: $customerId);
            $this->deals->save($deal);
            $this->audit->record('deal.created', $actorId, $deal->id,
                [], ['prospectId' => $prospectId, 'customerId' => $customerId, 'repeat' => true], 'deal');

            return $deal;
        });
    }
}
