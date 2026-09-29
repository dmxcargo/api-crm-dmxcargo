<?php

namespace App\Sales\Domain;

use App\Shared\Domain\BusinessRule;

final readonly class PhoneNumber
{
    public function __construct(public string $raw, public ?string $normalized) {}

    public static function parse(string $raw, bool $strict = true): self
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw));
        $normalized = self::normalize($raw);
        if ($strict && $normalized === null) {
            throw new BusinessRule('INVALID_PHONE', 'Nomor WhatsApp tidak valid. Gunakan format 08xxxxxxxxxx.', 422);
        }

        return new self($raw, $normalized);
    }

    public static function normalize(string $raw): ?string
    {
        $digits = preg_replace('/[^\d]/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '08')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }
        if (! str_starts_with($digits, '62') || strlen($digits) < 9 || strlen($digits) > 16) {
            return null;
        }

        return '+'.$digits;
    }
}
