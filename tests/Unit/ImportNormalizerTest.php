<?php

namespace Tests\Unit;

use App\Identity\Domain\Account;
use App\Identity\Domain\AccountRepository;
use App\Identity\Domain\Role;
use App\Sales\Application\ImportNormalizer;
use App\Sales\Domain\ImportRowStatus;
use App\Sales\Domain\ProspectRepository;
use PHPUnit\Framework\TestCase;

final class ImportNormalizerTest extends TestCase
{
    private function normalizer(): ImportNormalizer
    {
        $prospects = $this->createMock(ProspectRepository::class);
        $prospects->method('masters')->willReturnMap([
            ['prospect_sources', [['code' => 'GOOGLE_ADS', 'label' => 'Google Ads'], ['code' => 'REKOMENDASI', 'label' => 'Rekomendasi']]],
            ['industries', [['code' => 'LOGISTIK', 'label' => 'Logistik']]],
            ['lost_reasons', [['code' => 'HARGA', 'label' => 'Harga tidak kompetitif']]],
        ]);
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('byLogin')->willReturnCallback(fn ($login) => $login === 'sales.one'
            ? new Account('owner-id', 'Sales One', 'sales.one', 'sales.one@example.test', 'hash', Role::SALES) : null);

        return new ImportNormalizer($prospects, $accounts);
    }

    private function base(array $over = []): array
    {
        return ['account_name' => 'PT Contoh', 'phone' => '081234567890', 'prospect_source' => 'Google Ads',
            'owner_username' => 'sales.one', 'stage' => 'Follow Up', 'priority' => 'Warm',
            'customer_type' => 'B2B', ...$over];
    }

    public function test_canonical_row_is_valid(): void
    {
        $r = $this->normalizer()->normalize($this->base());
        $this->assertSame(ImportRowStatus::VALID, $r['status']);
        $this->assertSame('FOLLOW_UP', $r['normalized']['stage']);
        $this->assertSame('WARM', $r['normalized']['priority']);
        $this->assertSame('+6281234567890', $r['normalized']['phoneNormalized']);
    }

    public function test_won_without_closing_is_invalid(): void
    {
        $r = $this->normalizer()->normalize($this->base(['stage' => 'Won']));
        $this->assertSame(ImportRowStatus::INVALID, $r['status']);
        $this->assertContains('MISSING_REQUIRED_FIELD', $r['codes']);
    }

    public function test_won_with_closing_is_valid(): void
    {
        $r = $this->normalizer()->normalize($this->base(
            ['stage' => 'Won', 'closing_date' => '2026-08-20', 'closing_value' => '25000000']));
        $this->assertSame(ImportRowStatus::VALID, $r['status']);
        $this->assertSame('WON', $r['normalized']['stage']);
        $this->assertSame('25000000.00', $r['normalized']['closingValue']);
    }

    public function test_year_2206_is_rejected_without_autofix(): void
    {
        $r = $this->normalizer()->normalize($this->base(['entry_date' => '2206-05-01']));
        $this->assertSame(ImportRowStatus::INVALID, $r['status']);
        $this->assertContains('INVALID_DATE_RANGE', $r['codes']);
        $this->assertNull($r['normalized']['entryDate']);
    }

    public function test_legacy_hot_infers_follow_up_with_warning(): void
    {
        $r = $this->normalizer()->normalize($this->base(['Status' => 'Hot', 'stage' => '', 'priority' => '']));
        $this->assertSame(ImportRowStatus::WARNING, $r['status']);
        $this->assertSame('HOT', $r['normalized']['priority']);
        $this->assertSame('FOLLOW_UP', $r['normalized']['stage']);
        $this->assertContains('LEGACY_STAGE_INFERRED', $r['codes']);
    }

    public function test_legacy_closing_needs_review(): void
    {
        $r = $this->normalizer()->normalize($this->base(['Status' => 'Closing', 'stage' => '', 'priority' => 'Warm']));
        $this->assertSame('FOLLOW_UP', $r['normalized']['stage']);
        $this->assertContains('LEGACY_STAGE_INFERRED', $r['codes']);
    }

    public function test_excel_serial_and_decimal_are_parsed(): void
    {
        $r = $this->normalizer()->normalize($this->base(['entry_date' => '45824', 'potential_value' => 'Rp 15.000.000']));
        $this->assertSame('2025-06-16', $r['normalized']['entryDate']);
        $this->assertSame('15000000.00', $r['normalized']['potentialValue']);
    }

    public function test_unknown_owner_and_source_are_errors(): void
    {
        $r = $this->normalizer()->normalize($this->base(['owner_username' => 'hantu']));
        $this->assertContains('OWNER_NOT_FOUND', $r['codes']);
        $r = $this->normalizer()->normalize($this->base(['prospect_source' => 'Tidak Ada']));
        $this->assertContains('UNKNOWN_MASTER_VALUE', $r['codes']);
    }
}
