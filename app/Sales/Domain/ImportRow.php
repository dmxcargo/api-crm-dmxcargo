<?php

namespace App\Sales\Domain;

final class ImportRow
{
    public function __construct(public string $id, public string $jobId, public int $rowNumber, public array $raw,
        public ?array $normalized, public ImportRowStatus $status, public array $codes, public ?ImportDecision $decision,
        public ?array $approvedFields, public ?string $prospectId, public ?string $result, public int $version = 1) {}

    public function publicData(): array
    {
        return ['rowNumber' => $this->rowNumber, 'status' => $this->status->value, 'codes' => $this->codes,
            'decision' => $this->decision?->value, 'approvedFields' => $this->approvedFields,
            'prospectId' => $this->prospectId, 'result' => $this->result, 'version' => $this->version];
    }
}
