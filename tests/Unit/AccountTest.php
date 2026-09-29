<?php

namespace Tests\Unit;

use App\Identity\Domain\Account;
use App\Identity\Domain\Role;
use App\Shared\Domain\BusinessRule;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function test_last_admin_is_protected(): void
    {
        $a = new Account('id', 'Admin', 'admin', 'admin@example.test', 'hash', Role::ADMIN);
        $this->expectException(BusinessRule::class);
        $a->revise(['version' => 1, 'isActive' => false], 1);
    }

    public function test_stale_version_does_not_mutate_account(): void
    {
        $a = new Account('id', 'Admin', 'admin', 'admin@example.test', 'hash', Role::ADMIN);
        try {
            $a->revise(['version' => 9, 'name' => 'Changed'], 2);
            $this->fail();
        } catch (BusinessRule $e) {
            $this->assertSame('VERSION_CONFLICT', $e->errorCode);
            $this->assertSame('Admin', $a->name);
        }
    }

    public function test_billing_all_access_has_explicit_target_exception(): void
    {
        $this->assertTrue(Role::BILLING->allows('users.manage'));
        $this->assertTrue(Role::BILLING->allows('archives.manage'));
        $this->assertFalse(Role::BILLING->allows('targets.manage'));
        $this->assertTrue(Role::ADMIN->allows('targets.manage'));
        $this->assertFalse(Role::SALES->allows('sales.all'));
        $this->assertTrue(Role::SALES->allows('sales.own'));
    }

    public function test_domain_has_no_framework_or_http_dependency(): void
    {
        foreach (glob(__DIR__.'/../../app/Identity/Domain/*.php') as $file) {
            $text = file_get_contents($file);
            $this->assertStringNotContainsString('Illuminate\\', $text);
            $this->assertStringNotContainsString('Facades', $text);
        }
    }
}
