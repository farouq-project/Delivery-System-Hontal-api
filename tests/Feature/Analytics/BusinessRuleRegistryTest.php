<?php

namespace Tests\Feature\Analytics;

use App\Analytics\BusinessRuleRegistry;
use Tests\TestCase;

class BusinessRuleRegistryTest extends TestCase
{
    // ── classifyHealth ────────────────────────────────────────────────────────

    public function test_new_customer_with_zero_orders_is_healthy(): void
    {
        $this->assertSame('healthy', BusinessRuleRegistry::classifyHealth(0, 1.0, 0));
    }

    public function test_recent_high_success_rate_is_healthy(): void
    {
        $this->assertSame('healthy', BusinessRuleRegistry::classifyHealth(10, 0.9, 5));
    }

    public function test_ordered_31_days_ago_is_active(): void
    {
        $this->assertSame('active', BusinessRuleRegistry::classifyHealth(31, 0.9, 3));
    }

    public function test_low_success_rate_below_80_is_active(): void
    {
        $this->assertSame('active', BusinessRuleRegistry::classifyHealth(10, 0.75, 5));
    }

    public function test_ordered_61_days_ago_is_at_risk(): void
    {
        $this->assertSame('at_risk', BusinessRuleRegistry::classifyHealth(61, 0.9, 3));
    }

    public function test_success_rate_below_50_is_at_risk(): void
    {
        $this->assertSame('at_risk', BusinessRuleRegistry::classifyHealth(10, 0.4, 5));
    }

    public function test_ordered_91_days_ago_is_dormant(): void
    {
        $this->assertSame('dormant', BusinessRuleRegistry::classifyHealth(91, 0.9, 3));
    }

    public function test_ordered_181_days_ago_is_lost(): void
    {
        $this->assertSame('lost', BusinessRuleRegistry::classifyHealth(181, 0.9, 3));
    }

    public function test_boundary_exactly_at_lost_threshold(): void
    {
        // 180 days is still dormant, 181 is lost
        $this->assertSame('dormant', BusinessRuleRegistry::classifyHealth(180, 0.9, 3));
        $this->assertSame('lost',    BusinessRuleRegistry::classifyHealth(181, 0.9, 3));
    }

    public function test_boundary_exactly_at_at_risk_threshold(): void
    {
        $this->assertSame('active',  BusinessRuleRegistry::classifyHealth(60, 0.9, 3));
        $this->assertSame('at_risk', BusinessRuleRegistry::classifyHealth(61, 0.9, 3));
    }

    // ── classifySegment ───────────────────────────────────────────────────────

    public function test_gold_vip_level_is_vip_segment(): void
    {
        $this->assertSame('vip', BusinessRuleRegistry::classifySegment('gold', 'healthy', 0, 0));
    }

    public function test_platinum_vip_level_is_vip_segment(): void
    {
        $this->assertSame('vip', BusinessRuleRegistry::classifySegment('platinum', 'healthy', 100_000_000, 100));
    }

    public function test_dormant_health_is_dormant_segment(): void
    {
        $this->assertSame('dormant', BusinessRuleRegistry::classifySegment('standard', 'dormant', 100_000, 5));
    }

    public function test_lost_health_is_dormant_segment(): void
    {
        $this->assertSame('dormant', BusinessRuleRegistry::classifySegment('standard', 'lost', 100_000, 5));
    }

    public function test_high_spending_is_high_value_segment(): void
    {
        $this->assertSame('high_value', BusinessRuleRegistry::classifySegment('standard', 'healthy', 5_000_000, 1));
    }

    public function test_spending_below_threshold_is_not_high_value(): void
    {
        $this->assertSame('returning', BusinessRuleRegistry::classifySegment('standard', 'healthy', 4_999_999, 5));
    }

    public function test_two_or_more_orders_is_returning(): void
    {
        $this->assertSame('returning', BusinessRuleRegistry::classifySegment('standard', 'healthy', 0, 2));
    }

    public function test_one_order_is_new(): void
    {
        $this->assertSame('new', BusinessRuleRegistry::classifySegment('standard', 'healthy', 0, 1));
    }

    public function test_zero_orders_is_new(): void
    {
        $this->assertSame('new', BusinessRuleRegistry::classifySegment('standard', 'healthy', 0, 0));
    }

    // ── successRate ───────────────────────────────────────────────────────────

    public function test_success_rate_calculation(): void
    {
        $this->assertEqualsWithDelta(0.8, BusinessRuleRegistry::successRate(8, 10), 0.001);
    }

    public function test_success_rate_zero_when_no_terminal_orders(): void
    {
        $this->assertSame(0.0, BusinessRuleRegistry::successRate(0, 0));
    }

    public function test_success_rate_one_when_all_delivered(): void
    {
        $this->assertEqualsWithDelta(1.0, BusinessRuleRegistry::successRate(5, 5), 0.001);
    }

    // ── growthPct ─────────────────────────────────────────────────────────────

    public function test_growth_pct_positive(): void
    {
        $this->assertEqualsWithDelta(50.0, BusinessRuleRegistry::growthPct(15, 10), 0.01);
    }

    public function test_growth_pct_negative(): void
    {
        $this->assertEqualsWithDelta(-50.0, BusinessRuleRegistry::growthPct(5, 10), 0.01);
    }

    public function test_growth_pct_when_previous_is_zero_and_current_positive(): void
    {
        $this->assertEqualsWithDelta(100.0, BusinessRuleRegistry::growthPct(5, 0), 0.01);
    }

    public function test_growth_pct_when_both_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, BusinessRuleRegistry::growthPct(0, 0), 0.01);
    }

    // ── isRepeatCustomer ──────────────────────────────────────────────────────

    public function test_more_than_one_delivery_is_repeat(): void
    {
        $this->assertTrue(BusinessRuleRegistry::isRepeatCustomer(2));
        $this->assertTrue(BusinessRuleRegistry::isRepeatCustomer(10));
    }

    public function test_one_or_zero_deliveries_is_not_repeat(): void
    {
        $this->assertFalse(BusinessRuleRegistry::isRepeatCustomer(0));
        $this->assertFalse(BusinessRuleRegistry::isRepeatCustomer(1));
    }
}
