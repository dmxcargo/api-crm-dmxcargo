<?php

namespace Tests\Unit;

use App\Sales\Domain\CustomerType;
use App\Sales\Domain\PhoneNumber;
use App\Sales\Domain\Prospect;
use App\Sales\Domain\ProspectPriority;
use App\Shared\Domain\BusinessRule;
use PHPUnit\Framework\TestCase;

final class ProspectTest extends TestCase
{
    public function test_phone_normalization_id(): void
    {
        $this->assertSame('+6281234567890', PhoneNumber::normalize('0812-3456-7890'));
        $this->assertSame('+6281234567890', PhoneNumber::normalize('6281234567890'));
        $this->assertSame('+6281234567890', PhoneNumber::normalize('+62 812 3456 7890'));
        $this->assertNull(PhoneNumber::normalize('abc'));
        $this->assertNull(PhoneNumber::normalize('123'));
        $this->expectException(BusinessRule::class);
        PhoneNumber::parse('bukan-nomor');
    }

    public function test_customer_type_cannot_change_after_create(): void
    {
        $p = new Prospect('id', 'PT Contoh', PhoneNumber::parse('081234567890'), 'owner', 'GOOGLE_ADS',
            ProspectPriority::WARM, customerType: CustomerType::B2B);
        $this->expectException(BusinessRule::class);
        $p->revise(['version' => 1, 'customerType' => 'B2C']);
    }

    public function test_domain_has_no_framework_dependency(): void
    {
        foreach (glob(__DIR__.'/../../app/Sales/Domain/*.php') as $file) {
            $text = file_get_contents($file);
            $this->assertStringNotContainsString('Illuminate\\', $text);
            $this->assertStringNotContainsString('Facades', $text);
        }
    }
}
