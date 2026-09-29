<?php

namespace App\Sales\Domain;

final class Target
{
    public function __construct(public string $id, public string $userId, public TargetPeriod $periodType,
        public string $period, public string $targetValue, public int $version = 1) {}

    public function publicData(): array
    {
        return ['id' => $this->id, 'userId' => $this->userId, 'periodType' => $this->periodType->value,
            'period' => $this->period, 'targetValue' => $this->targetValue, 'version' => $this->version];
    }
}
