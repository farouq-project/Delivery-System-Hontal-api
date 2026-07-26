<?php

namespace App\Services\Growth;

use Illuminate\Support\Facades\DB;

/**
 * Data access layer for growth metrics.
 *
 * Responsible only for querying raw numbers from the database.
 * No formatting, no aggregation logic — that belongs in the service layer above.
 *
 * All queries are scoped to a date range and optionally to a merchant.
 * Passing null for $merchantId returns platform-wide (all merchants).
 *
 * Phase 6.2 — Architecture stub. Implement methods as Growth Dashboard is built.
 */
class GrowthMetricsRepository
{
    // ─── Merchant counts ────────────────────────────────────────────────

    /**
     * Count merchants whose subscription started within the given date range.
     * Excludes trial merchants if $includeTrial is false.
     */
    public function newMerchantsInPeriod(\Carbon\Carbon $from, \Carbon\Carbon $to, bool $includeTrial = false): int
    {
        $q = DB::table('merchant_subscriptions')
            ->whereBetween('started_at', [$from, $to]);

        if (!$includeTrial) {
            $q->where('status', '!=', 'trial');
        }

        return $q->count();
    }

    /**
     * Count merchants active (at least one order created) within the date range.
     */
    public function activeMerchantsInPeriod(\Carbon\Carbon $from, \Carbon\Carbon $to): int
    {
        return DB::table('delivery_orders')
            ->whereBetween('order_created_at', [$from, $to])
            ->distinct('merchant_id')
            ->count('merchant_id');
    }

    // ─── Order counts ───────────────────────────────────────────────────

    /**
     * Total orders created in the period, optionally scoped to one merchant.
     */
    public function totalOrdersInPeriod(\Carbon\Carbon $from, \Carbon\Carbon $to, ?int $merchantId = null): int
    {
        $q = DB::table('delivery_orders')->whereBetween('order_created_at', [$from, $to]);
        if ($merchantId !== null) {
            $q->where('merchant_id', $merchantId);
        }
        return $q->count();
    }

    /**
     * Delivered order count in the period, optionally scoped to one merchant.
     */
    public function deliveredOrdersInPeriod(\Carbon\Carbon $from, \Carbon\Carbon $to, ?int $merchantId = null): int
    {
        $q = DB::table('delivery_orders')
            ->whereBetween('order_created_at', [$from, $to])
            ->where('status', 'delivered');
        if ($merchantId !== null) {
            $q->where('merchant_id', $merchantId);
        }
        return $q->count();
    }

    // ─── Revenue ────────────────────────────────────────────────────────

    /**
     * Sum of order_value for delivered orders in period (proxy for GMV).
     * Returns 0.00 when no orders match.
     */
    public function revenueInPeriod(\Carbon\Carbon $from, \Carbon\Carbon $to, ?int $merchantId = null): float
    {
        $q = DB::table('delivery_orders')
            ->whereBetween('order_created_at', [$from, $to])
            ->where('status', 'delivered');
        if ($merchantId !== null) {
            $q->where('merchant_id', $merchantId);
        }
        return (float) ($q->sum('order_value') ?? 0);
    }

    // ─── Customer growth ────────────────────────────────────────────────

    /**
     * New customers (first-ever order) created within the period for a merchant.
     */
    public function newCustomersInPeriod(\Carbon\Carbon $from, \Carbon\Carbon $to, int $merchantId): int
    {
        return DB::table('customers')
            ->where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    // ─── Retention proxy ────────────────────────────────────────────────

    /**
     * Customers who placed at least 2 orders in the period (repeat buyers).
     */
    public function repeatBuyersInPeriod(\Carbon\Carbon $from, \Carbon\Carbon $to, int $merchantId): int
    {
        return DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->whereBetween('order_created_at', [$from, $to])
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();
    }
}
