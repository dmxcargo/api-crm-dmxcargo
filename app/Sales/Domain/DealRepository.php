<?php

namespace App\Sales\Domain;

interface DealRepository
{
    public function find(string $id, bool $lock = false): ?Deal;

    public function openFor(string $prospectId, bool $lock = false): ?Deal;

    public function save(Deal $deal): void;

    public function nextId(): string;

    public function nextDealNumber(): string;
}
