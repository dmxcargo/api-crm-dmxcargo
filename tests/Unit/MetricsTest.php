<?php

namespace Tests\Unit;

use App\Sales\Application\CsvSanitizer;
use App\Sales\Application\MetricsService;
use App\Sales\Domain\TargetPeriod;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    public function test_month_bounds_use_last_day(): void
    {
        $this->assertSame(['from' => '2026-02-01', 'to' => '2026-02-28'], MetricsService::bounds(TargetPeriod::MONTH, '2026-02'));
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], MetricsService::bounds(TargetPeriod::YEAR, '2026'));
    }

    public function test_formula_prefixes_are_neutralized(): void
    {
        $this->assertSame("'=CMD(1)", CsvSanitizer::cell('=CMD(1)'));
        $this->assertSame("' +evil", CsvSanitizer::cell(' +evil'));
        $this->assertSame("'\t@x", CsvSanitizer::cell("\t@x"));
        $this->assertSame("'".chr(31).'-9', CsvSanitizer::cell(chr(31).'-9'));
        $this->assertSame('PT Aman', CsvSanitizer::cell('PT Aman'));
        $this->assertSame('15000000', CsvSanitizer::cell(15000000.00));
        $this->assertSame('', CsvSanitizer::cell(null));
    }
}
