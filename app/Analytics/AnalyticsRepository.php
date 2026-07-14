<?php

namespace App\Analytics;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All SQL aggregation queries for the BI layer.
 *
 * This class owns data retrieval; it never owns business rules.
 * All thresholds and qualifications come from BusinessRuleRegistry.
 * Methods return raw scalars or Eloquent/Query Builder collections —
 * callers are responsible for shaping the final response arrays.
 */
class AnalyticsRepository
{
    // ── Revenue & Delivered Orders ────────────────────────────────────────────

    /**
     * Count and sum revenue for delivered orders on a specific date
     * (matched against the delivered_at column).
     *
     * @return array{count: int, revenue: float}
     */
    public function deliveredRevenueForDay(int $merchantId, Carbon $date): array
    {
        $row = DeliveryOrder::where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereDate('delivered_at', $date->toDateString())
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(order_value), 0) as revenue')
            ->first();

        return [
            'count'   => (int) ($row->cnt ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
        ];
    }

    /**
     * Count and sum revenue for delivered orders within a date range
     * (matched against the delivered_at column).
     *
     * @return array{count: int, revenue: float}
     */
    public function deliveredRevenueForPeriod(int $merchantId, Carbon $from, Carbon $to): array
    {
        $row = DeliveryOrder::where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereBetween('delivered_at', [$from, $to])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(order_value), 0) as revenue')
            ->first();

        return [
            'count'   => (int) ($row->cnt ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
        ];
    }

    // ── Order Counts ──────────────────────────────────────────────────────────

    public function orderCountForDay(int $merchantId, Carbon $date): int
    {
        return DeliveryOrder::where('merchant_id', $merchantId)
            ->whereDate('order_created_at', $date->toDateString())
            ->count();
    }

    public function orderCountForPeriod(int $merchantId, Carbon $from, Carbon $to): int
    {
        return DeliveryOrder::where('merchant_id', $merchantId)
            ->whereBetween('order_created_at', [$from, $to])
            ->count();
    }

    // ── Success Rate ──────────────────────────────────────────────────────────

    /**
     * Count terminal orders (delivered + failed + cancelled) for a given day
     * alongside the delivered sub-count, ready for success-rate calculation.
     *
     * @return array{total: int, delivered: int}
     */
    public function terminalCountsForDay(int $merchantId, Carbon $date): array
    {
        $row = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('status', ['delivered', 'failed', 'cancelled'])
            ->whereDate('order_created_at', $date->toDateString())
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered_cnt')
            ->first();

        return [
            'total'     => (int) ($row->total ?? 0),
            'delivered' => (int) ($row->delivered_cnt ?? 0),
        ];
    }

    // ── Drivers ───────────────────────────────────────────────────────────────

    public function activeDriverCount(int $merchantId): int
    {
        return Driver::where('merchant_id', $merchantId)
            ->whereIn('status', ['available', 'on_delivery', 'delivering'])
            ->count();
    }

    /** Active drivers currently offline (i.e., should be working but aren't). */
    public function offlineActiveDrivers(int $merchantId): Collection
    {
        return Driver::where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->where('status', 'offline')
            ->get(['id', 'driver_name']);
    }

    // ── Customers ─────────────────────────────────────────────────────────────

    public function totalCustomers(int $merchantId): int
    {
        return Customer::where('merchant_id', $merchantId)->count();
    }

    public function newCustomersForPeriod(int $merchantId, Carbon $from, Carbon $to): int
    {
        return Customer::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /**
     * Count customers with more than REPEAT_CUSTOMER_MIN_DELIVERIES delivered
     * orders. Computed live from delivery_orders (no customer_domain required).
     */
    public function repeatCustomerCount(int $merchantId): int
    {
        return DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > ' . BusinessRuleRegistry::REPEAT_CUSTOMER_MIN_DELIVERIES)
            ->get()
            ->count();
    }

    /**
     * Count repeat customers using the pre-computed customer_profiles table.
     * Requires customer_domain feature to be enabled.
     */
    public function repeatCustomerCountFromProfiles(int $merchantId): int
    {
        return CustomerProfile::where('merchant_id', $merchantId)
            ->where('total_orders', '>', BusinessRuleRegistry::REPEAT_CUSTOMER_MIN_DELIVERIES)
            ->count();
    }

    /**
     * Count dormant/lost customers from customer_profiles.
     * Requires customer_domain feature to be enabled.
     */
    public function dormantCustomerCountFromProfiles(int $merchantId): int
    {
        return CustomerProfile::where('merchant_id', $merchantId)
            ->whereIn('health_status', BusinessRuleRegistry::SEGMENT_DORMANT_STATUSES)
            ->count();
    }

    public function customersWithoutGpsCount(int $merchantId): int
    {
        return Customer::where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('default_latitude')
            ->count();
    }

    // ── Cluster Summary ───────────────────────────────────────────────────────

    /**
     * Aggregated per-cluster metrics across all time.
     * Returns a Collection of stdClass rows with:
     *   cluster, total_orders, revenue, deliveries, terminal_cnt
     */
    public function clusterSummary(int $merchantId, int $limit = 20): Collection
    {
        return DB::table('delivery_orders as o')
            ->leftJoin('customers as c', function ($join) {
                $join->on('o.customer_id', '=', 'c.id')
                     ->whereNull('c.deleted_at');
            })
            ->where('o.merchant_id', $merchantId)
            ->whereNull('o.deleted_at')
            ->select([
                DB::raw('COALESCE(c.cluster, "No Cluster") as cluster'),
                DB::raw('COUNT(o.id) as total_orders'),
                DB::raw('COALESCE(SUM(CASE WHEN o.status = "delivered" THEN o.order_value ELSE 0 END), 0) as revenue'),
                DB::raw('SUM(CASE WHEN o.status = "delivered" THEN 1 ELSE 0 END) as deliveries'),
                DB::raw('SUM(CASE WHEN o.status IN ("delivered", "failed", "cancelled") THEN 1 ELSE 0 END) as terminal_cnt'),
            ])
            ->groupBy('c.cluster')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();
    }

    // ── Top Customers ─────────────────────────────────────────────────────────

    /**
     * Top N customers by total delivered spending.
     * Returns a Collection of stdClass rows with:
     *   customer_id, customer_name, total_orders, total_spending
     */
    public function topCustomers(int $merchantId, int $limit = 10): Collection
    {
        return DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->select([
                'customer_id',
                'customer_name',
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('COALESCE(SUM(order_value), 0) as total_spending'),
            ])
            ->groupBy('customer_id', 'customer_name')
            ->orderByDesc('total_spending')
            ->limit($limit)
            ->get();
    }

    // ── Recent Activity ───────────────────────────────────────────────────────

    public function recentOrders(int $merchantId, int $limit = 15): Collection
    {
        return DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('status', ['delivered', 'failed', 'cancelled', 'assigned', 'in_transit'])
            ->select(['id', 'order_number', 'customer_name', 'status', 'driver_id', 'updated_at'])
            ->with('driver:id,driver_name')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    // ── Requires Attention ────────────────────────────────────────────────────

    public function delayedDeliveryCount(int $merchantId): int
    {
        return DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('status', ['assigned', 'in_transit'])
            ->where('order_created_at', '<', Carbon::now()->subHours(BusinessRuleRegistry::ATTENTION_DELAYED_HOURS))
            ->count();
    }

    public function failedTodayCount(int $merchantId): int
    {
        return DeliveryOrder::where('merchant_id', $merchantId)
            ->where('status', 'failed')
            ->whereDate('failed_at', Carbon::today())
            ->count();
    }
}
