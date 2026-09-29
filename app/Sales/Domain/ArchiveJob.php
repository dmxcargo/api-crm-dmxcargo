<?php

namespace App\Sales\Domain;

final class ArchiveJob
{
    public function __construct(public string $id, public string $type, public array $filters,
        public ArchiveStatus $status, public ?array $manifest, public ?string $path, public ?string $sha256,
        public ?string $secondCopyPath, public bool $secondCopyVerified, public bool $purged, public ?string $result,
        public string $createdBy, public int $version = 1) {}

    public function publicData(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'status' => $this->status->value,
            'manifest' => $this->manifest, 'sha256' => $this->sha256,
            'secondCopyVerified' => $this->secondCopyVerified, 'purged' => $this->purged,
            'result' => $this->result, 'version' => $this->version];
    }
}
