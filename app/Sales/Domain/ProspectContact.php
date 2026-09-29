<?php

namespace App\Sales\Domain;

use App\Shared\Domain\BusinessRule;

final class ProspectContact
{
    public function __construct(
        public string $id,
        public string $prospectId,
        public string $name,
        public ?string $position = null,
        public ?PhoneNumber $phone = null,
        public ?string $email = null,
        public bool $primary = false,
    ) {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new BusinessRule('INVALID_CONTACT', 'Nama kontak wajib diisi dan maksimal 150 karakter.', 422);
        }
        $this->name = $name;
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'prospectId' => $this->prospectId, 'name' => $this->name,
            'position' => $this->position, 'phone' => $this->phone?->raw,
            'phoneNormalized' => $this->phone?->normalized, 'email' => $this->email, 'primary' => $this->primary];
    }
}
