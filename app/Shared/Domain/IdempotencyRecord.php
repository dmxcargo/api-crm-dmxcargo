<?php

namespace App\Shared\Domain;

final readonly class IdempotencyRecord
{
    public function __construct(
        public string $key,
        public string $actorId,
        public string $route,
        public string $payloadHash,
        public ?string $resourceId,
    ) {}

    public static function hash(array $data): string
    {
        ksort($data);

        return hash('sha256', (string) json_encode($data));
    }
}
