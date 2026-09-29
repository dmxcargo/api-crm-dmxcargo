<?php

namespace App\Sales\Domain;

interface ExportRepository
{
    public function find(string $id, bool $lock = false): ?ExportJob;

    public function save(ExportJob $job): void;

    public function nextId(): string;
}
