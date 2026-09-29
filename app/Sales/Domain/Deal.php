<?php

namespace App\Sales\Domain;

use App\Shared\Domain\BusinessRule;

final class Deal
{
    public function __construct(
        public string $id,
        public string $prospectId,
        public DealStatus $status = DealStatus::OPEN,
        public ?string $customerId = null,
        public ?string $dealNumber = null,
        public ?string $quotationNumber = null,
        public ?string $potentialValue = null,
        public ?string $quotationValue = null,
        public ?string $closingValue = null,
        public ?string $closingDate = null,
        public ?string $lostReasonCode = null,
        public ?string $paymentStatus = null,
        public ?string $closingOwnerId = null,
        public int $version = 1,
    ) {}

    public function revise(array $data): void
    {
        if ((int) $data['version'] !== $this->version) {
            throw new BusinessRule('VERSION_CONFLICT', 'Data telah diubah pengguna lain. Muat ulang data sebelum menyimpan.');
        }
        if ($this->status !== DealStatus::OPEN) {
            throw new BusinessRule('DEAL_CLOSED', 'Deal yang sudah Won/Lost tidak dapat diubah. Buat repeat order untuk transaksi baru.', 409);
        }
        foreach (['quotationNumber', 'potentialValue', 'quotationValue', 'paymentStatus'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->$field = $data[$field];
            }
        }
        $this->version++;
    }

    public function markWon(string $closingDate, string $closingValue): void
    {
        if ($this->status !== DealStatus::OPEN) {
            throw new BusinessRule('DEAL_CLOSED', 'Deal sudah diputuskan.', 409);
        }
        if ((float) $closingValue < 0) {
            throw new BusinessRule('INVALID_MONEY', 'Closing value tidak boleh negatif.', 422);
        }
        $this->status = DealStatus::WON;
        $this->closingDate = $closingDate;
        $this->closingValue = $closingValue;
        $this->version++;
    }

    public function markLost(string $lostReasonCode): void
    {
        if ($this->status !== DealStatus::OPEN) {
            throw new BusinessRule('DEAL_CLOSED', 'Deal sudah diputuskan.', 409);
        }
        $this->status = DealStatus::LOST;
        $this->lostReasonCode = $lostReasonCode;
        $this->version++;
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'prospectId' => $this->prospectId, 'customerId' => $this->customerId,
            'dealNumber' => $this->dealNumber, 'quotationNumber' => $this->quotationNumber,
            'potentialValue' => $this->potentialValue, 'quotationValue' => $this->quotationValue,
            'closingValue' => $this->closingValue, 'closingDate' => $this->closingDate, 'status' => $this->status->value,
            'lostReasonCode' => $this->lostReasonCode, 'paymentStatus' => $this->paymentStatus,
            'closingOwnerId' => $this->closingOwnerId, 'version' => $this->version];
    }
}
