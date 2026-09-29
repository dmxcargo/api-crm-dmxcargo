<?php

namespace App\Sales\Domain;

use App\Shared\Domain\BusinessRule;

final class Prospect
{
    public function __construct(
        public string $id,
        public string $accountName,
        public PhoneNumber $phone,
        public string $ownerUserId,
        public string $sourceCode,
        public ProspectPriority $priority,
        public ProspectStage $stage = ProspectStage::NEW,
        public ?string $legacyId = null,
        public ?int $legacySourceRow = null,
        public ?string $entryDate = null,
        public ?string $picName = null,
        public ?string $picPosition = null,
        public ?string $email = null,
        public ?string $city = null,
        public ?string $province = null,
        public ?string $industryCode = null,
        public ?string $lastProgress = null,
        public ?string $nextFollowUpAt = null,
        public ?string $nextAction = null,
        public ?string $potentialValue = null,
        public ?string $paymentStatus = null,
        public ?CustomerType $customerType = null,
        public ?string $notes = null,
        public bool $archived = false,
        public int $version = 1,
    ) {
        $this->accountName = self::clean($accountName, 200, 'Nama account');
    }

    public function revise(array $data): void
    {
        if ((int) $data['version'] !== $this->version) {
            throw new BusinessRule('VERSION_CONFLICT', 'Data telah diubah pengguna lain. Muat ulang data sebelum menyimpan.');
        }
        if (array_key_exists('customerType', $data) && $data['customerType'] !== ($this->customerType?->value)) {
            throw new BusinessRule('CUSTOMER_TYPE_IMMUTABLE', 'Customer Type tidak dapat diubah setelah entry dibuat.', 422);
        }
        foreach (['accountName' => 200, 'picName' => 150, 'picPosition' => 100, 'city' => 100,
            'province' => 100, 'nextAction' => 250] as $field => $max) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $this->$field = self::clean($data[$field], $max, $field);
            } elseif (array_key_exists($field, $data)) {
                $this->$field = null;
            }
        }
        foreach (['lastProgress', 'notes', 'email', 'entryDate', 'nextFollowUpAt',
            'potentialValue', 'paymentStatus', 'legacyId', 'industryCode'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->$field = $data[$field];
            }
        }
        if (array_key_exists('legacySourceRow', $data)) {
            $this->legacySourceRow = $data['legacySourceRow'] !== null ? (int) $data['legacySourceRow'] : null;
        }
        $this->version++;
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'legacyId' => $this->legacyId, 'legacySourceRow' => $this->legacySourceRow,
            'entryDate' => $this->entryDate, 'accountName' => $this->accountName, 'picName' => $this->picName,
            'picPosition' => $this->picPosition, 'phone' => $this->phone->raw, 'phoneNormalized' => $this->phone->normalized,
            'email' => $this->email, 'city' => $this->city, 'province' => $this->province, 'industryCode' => $this->industryCode,
            'sourceCode' => $this->sourceCode, 'ownerUserId' => $this->ownerUserId, 'stage' => $this->stage->value,
            'priority' => $this->priority->value, 'lastProgress' => $this->lastProgress, 'nextFollowUpAt' => $this->nextFollowUpAt,
            'nextAction' => $this->nextAction, 'potentialValue' => $this->potentialValue, 'paymentStatus' => $this->paymentStatus,
            'customerType' => $this->customerType?->value, 'notes' => $this->notes,
            'archived' => $this->archived, 'version' => $this->version];
    }

    public function auditData(): array
    {
        return ['stage' => $this->stage->value, 'ownerUserId' => $this->ownerUserId,
            'customerType' => $this->customerType?->value, 'version' => $this->version];
    }

    private static function clean(string $value, int $max, string $field): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if ($value === '' || mb_strlen($value) > $max) {
            throw new BusinessRule('INVALID_PROSPECT', "$field wajib diisi dan maksimal $max karakter.", 422);
        }

        return $value;
    }
}
