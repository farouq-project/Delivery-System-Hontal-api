<?php

namespace App\Analytics;

/**
 * Single source of truth for all business classification rules.
 *
 * Every threshold, qualifying status, and classification formula lives here.
 * Services that previously held these values inline now delegate here,
 * so a rule change in one place propagates everywhere.
 */
class BusinessRuleRegistry
{
    // ── Revenue ──────────────────────────────────────────────────────────────
    /** Only orders with this status count toward revenue */
    const REVENUE_STATUS = 'delivered';

    // ── Customer Health — recency thresholds (days since last order) ──────────
    const HEALTH_LOST_DAYS    = 180;
    const HEALTH_DORMANT_DAYS = 90;
    const HEALTH_AT_RISK_DAYS = 60;
    const HEALTH_ACTIVE_DAYS  = 30;

    // ── Customer Health — success rate thresholds (ratio 0–1) ────────────────
    const HEALTH_AT_RISK_SUCCESS_RATE = 0.5;
    const HEALTH_ACTIVE_SUCCESS_RATE  = 0.8;

    // ── Customer Segmentation ────────────────────────────────────────────────
    const SEGMENT_VIP_LEVELS       = ['gold', 'platinum'];
    const SEGMENT_DORMANT_STATUSES = ['dormant', 'lost'];
    const SEGMENT_HIGH_VALUE_IDR   = 5_000_000;
    const SEGMENT_RETURNING_ORDERS = 2;

    // ── Repeat Customer ──────────────────────────────────────────────────────
    /** A customer with MORE than this many delivered orders is "repeat" */
    const REPEAT_CUSTOMER_MIN_DELIVERIES = 1;

    // ── Requires Attention ───────────────────────────────────────────────────
    const ATTENTION_DELAYED_HOURS = 4;

    // ── Classifiers ─────────────────────────────────────────────────────────

    /**
     * Classify a customer's health status from recency and success rate.
     *
     * healthy  — ordered in last 30 days, success rate ≥ 80%
     * active   — ordered in last 30–60 days OR success rate 50–80%
     * at_risk  — ordered in last 60–90 days OR success rate < 50%
     * dormant  — ordered 90–180 days ago
     * lost     — no order in 180+ days
     */
    public static function classifyHealth(int $daysSinceLast, float $successRate, int $totalOrders): string
    {
        if ($totalOrders === 0) {
            return 'healthy'; // brand-new customer
        }

        if ($daysSinceLast > self::HEALTH_LOST_DAYS)    return 'lost';
        if ($daysSinceLast > self::HEALTH_DORMANT_DAYS) return 'dormant';

        if ($daysSinceLast > self::HEALTH_AT_RISK_DAYS || $successRate < self::HEALTH_AT_RISK_SUCCESS_RATE) {
            return 'at_risk';
        }

        if ($daysSinceLast > self::HEALTH_ACTIVE_DAYS || $successRate < self::HEALTH_ACTIVE_SUCCESS_RATE) {
            return 'active';
        }

        return 'healthy';
    }

    /**
     * Classify a customer's segment from VIP level, health, spending, and orders.
     *
     * vip        — VIP level gold or platinum
     * dormant    — health status is dormant or lost
     * high_value — total spending ≥ Rp 5,000,000
     * returning  — 2+ orders
     * new        — otherwise
     */
    public static function classifySegment(
        string $vipLevel,
        string $healthStatus,
        float $totalSpending,
        int $totalOrders
    ): string {
        if (in_array($vipLevel, self::SEGMENT_VIP_LEVELS)) {
            return 'vip';
        }

        if (in_array($healthStatus, self::SEGMENT_DORMANT_STATUSES)) {
            return 'dormant';
        }

        if ($totalSpending >= self::SEGMENT_HIGH_VALUE_IDR) {
            return 'high_value';
        }

        if ($totalOrders >= self::SEGMENT_RETURNING_ORDERS) {
            return 'returning';
        }

        return 'new';
    }

    /**
     * A customer is "repeat" when their delivered order count exceeds the minimum.
     */
    public static function isRepeatCustomer(int $deliveredOrders): bool
    {
        return $deliveredOrders > self::REPEAT_CUSTOMER_MIN_DELIVERIES;
    }

    /**
     * Delivery success rate as a ratio (0.0–1.0).
     * $terminal = total delivered + failed + cancelled orders.
     */
    public static function successRate(int $delivered, int $terminal): float
    {
        return $terminal > 0 ? $delivered / $terminal : 0.0;
    }

    /**
     * Month-over-month (or any period) customer growth percentage.
     * Returns 100.0 when previous is 0 and current > 0; 0.0 when both are 0.
     */
    public static function growthPct(int $current, int $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }
}
