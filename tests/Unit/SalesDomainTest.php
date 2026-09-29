<?php

namespace Tests\Unit;

use App\Sales\Domain\Deal;
use App\Sales\Domain\DealStatus;
use App\Sales\Domain\StageTransition;
use App\Shared\Domain\BusinessRule;
use PHPUnit\Framework\TestCase;

final class SalesDomainTest extends TestCase
{
    public function test_transition_matrix(): void
    {
        $this->assertTrue(StageTransition::can('NEW', 'FOLLOW_UP', false));
        $this->assertTrue(StageTransition::can('NEW', 'QUOTATION', false));
        $this->assertFalse(StageTransition::can('FOLLOW_UP', 'NEW', false));
        $this->assertFalse(StageTransition::can('NEW', 'WON', true));
        $this->assertFalse(StageTransition::can('NEW', 'LOST', true));
        $this->assertFalse(StageTransition::can('NEW', 'MAINTENANCE', true));
        $this->assertTrue(StageTransition::can('WON', 'MAINTENANCE', false));
        $this->assertFalse(StageTransition::can('WON', 'FOLLOW_UP', false));
        $this->assertTrue(StageTransition::can('WON', 'FOLLOW_UP', true));
        $this->assertTrue(StageTransition::can('LOST', 'FOLLOW_UP', true));
        $this->assertFalse(StageTransition::can('NEW', 'NEW', true));
    }

    public function test_closed_deal_rejects_changes(): void
    {
        $deal = new Deal('id', 'prospect', DealStatus::OPEN);
        $deal->markWon('2026-09-09', '1000');
        $this->assertSame(DealStatus::WON, $deal->status);
        $this->expectException(BusinessRule::class);
        $deal->revise(['version' => 2, 'quotationValue' => '5']);
    }

    public function test_sales_domain_has_no_framework_dependency(): void
    {
        foreach (glob(__DIR__.'/../../app/Sales/Domain/*.php') as $file) {
            $text = file_get_contents($file);
            $this->assertStringNotContainsString('Illuminate\\', $text);
            $this->assertStringNotContainsString('Facades', $text);
        }
    }
}
