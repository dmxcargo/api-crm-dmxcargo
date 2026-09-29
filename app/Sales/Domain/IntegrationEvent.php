<?php

namespace App\Sales\Domain;

final readonly class IntegrationEvent
{
    public function __construct(
        public string $type,
        public string $entityType,
        public string $entityId,
        public string $actorId,
        public array $payload = [],
    ) {}
}
