<?php

namespace App\Sales\Domain;

final class ExportJob
{
    public function __construct(public string $id, public ExportType $type, public array $filters, public array $columns,
        public ExportStatus $status, public ?string $path, public ?string $expiresAt, public ?string $result,
        public string $createdBy, public int $version = 1) {}

    public function publicData(): array
    {
        return ['id' => $this->id, 'type' => $this->type->value, 'status' => $this->status->value,
            'expiresAt' => $this->expiresAt, 'result' => $this->result, 'version' => $this->version];
    }
}
