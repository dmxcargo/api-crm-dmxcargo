<?php

namespace App\Sales\Domain;

use App\Shared\Domain\BusinessRule;

final class Customer
{
    public function __construct(
        public string $id,
        public string $accountName,
        public ?string $phoneRaw = null,
        public ?string $phoneNormalized = null,
        public ?string $email = null,
        public ?string $city = null,
        public ?string $province = null,
        public ?string $industryCode = null,
        public bool $active = true,
        public int $version = 1,
    ) {
        $accountName = trim(preg_replace('/\s+/', ' ', $accountName));
        if ($accountName === '' || mb_strlen($accountName) > 200) {
            throw new BusinessRule('INVALID_CUSTOMER', 'Nama account wajib diisi dan maksimal 200 karakter.', 422);
        }
        $this->accountName = $accountName;
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'accountName' => $this->accountName, 'phone' => $this->phoneRaw,
            'phoneNormalized' => $this->phoneNormalized, 'email' => $this->email, 'city' => $this->city,
            'province' => $this->province, 'industryCode' => $this->industryCode,
            'active' => $this->active, 'version' => $this->version];
    }
}
