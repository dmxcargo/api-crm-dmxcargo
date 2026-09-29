<?php

namespace App\Sales\Domain;

final class ImportJob
{
    public function __construct(public string $id, public int $schemaVersion, public string $sourceSystem,
        public string $fileSha256, public string $fileName, public ImportJobStatus $status, public int $totalRows,
        public array $counters, public string $createdBy, public int $version = 1) {}

    public function publicData(): array
    {
        return ['id' => $this->id, 'schemaVersion' => $this->schemaVersion, 'sourceSystem' => $this->sourceSystem,
            'fileSha256' => $this->fileSha256, 'fileName' => $this->fileName, 'status' => $this->status->value,
            'totalRows' => $this->totalRows, 'counters' => $this->counters, 'version' => $this->version];
    }
}
