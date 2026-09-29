<?php

namespace Tests\Unit;

use App\Sales\Application\CsvParser;
use App\Shared\Domain\BusinessRule;
use PHPUnit\Framework\TestCase;

final class CsvParserTest extends TestCase
{
    public function test_parses_rfc4180_with_bom_quotes_and_blank_lines(): void
    {
        $csv = "\xEF\xBB\xBFaccount_name,phone,notes\nPT A,081234567890,\"baris satu\nbaris dua\"\n\nPT B,081234567891,ok\n";
        [$header, $rows] = CsvParser::parse($csv);
        $this->assertSame(['account_name', 'phone', 'notes'], $header);
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['rowNumber']);
        $this->assertSame("baris satu\nbaris dua", $rows[0]['data']['notes']);
        $this->assertSame(2, $rows[1]['rowNumber']);
    }

    public function test_header_only_csv_has_no_rows(): void
    {
        [$header, $rows] = CsvParser::parse("account_name\n\n");
        $this->assertSame(['account_name'], $header);
        $this->assertSame([], $rows);
    }

    public function test_empty_content_is_rejected(): void
    {
        $this->expectException(BusinessRule::class);
        CsvParser::parse('');
    }
}
