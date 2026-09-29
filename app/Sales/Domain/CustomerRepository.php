<?php

namespace App\Sales\Domain;

interface CustomerRepository
{
    public function find(string $id, bool $lock = false): ?Customer;

    public function save(Customer $customer): void;

    /** @return Customer[] */
    public function candidates(?string $phoneNormalized, ?string $email, ?string $accountName): array;

    public function nextId(): string;
}
