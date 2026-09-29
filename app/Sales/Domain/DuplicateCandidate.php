<?php

namespace App\Sales\Domain;

final readonly class DuplicateCandidate
{
    public function __construct(public string $prospectId, public string $reason, public string $matchedValue) {}

    public function publicData(): array
    {
        return ['prospectId' => $this->prospectId, 'reason' => $this->reason, 'matchedValue' => $this->matchedValue];
    }
}
