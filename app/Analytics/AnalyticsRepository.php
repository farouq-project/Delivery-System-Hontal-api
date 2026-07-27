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

    // ── Operations ────────────────────────────────────────────────────────────

    public function pendingOrderCount(int $merchantId): int
    {
        return DeliveryOrder::where('merchant_id', $merchantId)
            ->where('status', 'pending')
            ->count();
    }

    public function pendingAssignmentCount(int $merchantId): int
    {
        return DeliveryOrder::where('merchant_id', $merchantId)
            ->where('status', 'pending')
            ->whereNull('driver_id')
            ->count();
    }

    // ── Driver Ranking ────────────────────────────────────────────────────────

    /**
     * Per-driver aggregated performance across all time.
     * Returns: driver_id, driver_name, status, completed, failed, revenue, total_assigned
     */
    public function driverRanking(int $merchantId, int $limit = 20): Collection
    {
        return DB::table('delivery_orders as o')
            ->join('drivers as d', 'd.id', '=', 'o.driver_id')
            ->where('o.merchant_id', $merchantId)
            ->whereNull('o.deleted_at')
            ->where('d.is_active', true)
            ->select([
                'd.id as driver_id',
                'd.driver_name',
                'd.status',
                DB::raw('COUNT(CASE WHEN o.status = "delivered" THEN 1 END) as completed'),
                DB::raw('COALESCE(SUM(CASE WHEN o.status = "delivered" THEN o.order_value ELSE 0 END), 0) as revenue'),
                DB::raw('COUNT(CASE WHEN o.status = "failed" THEN 1 END) as failed'),
                DB::raw('COUNT(o.id) as total_assigned'),
            ])
            ->groupBy('d.id', 'd.driver_name', 'd.status')
            ->orderByDesc('completed')
            ->limit($limit)
            ->get();
    }

    // ── Product Ranking ───────────────────────────────────────────────────────

    /**
     * Per-product aggregated metrics grouped by product_name.
     * Returns: product_name, total_orders, delivered, revenue
     */
    public function productRanking(int $merchantId, int $limit = 20): Collection
    {
        return DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->whereNotNull('product_name')
            ->where('product_name', '!=', '')
            ->select([
                'product_name',
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('COUNT(CASE WHEN status = "delivered" THEN 1 END) as delivered'),
                DB::raw('COALESCE(SUM(CASE WHEN status = "delivered" THEN order_value ELSE 0 END), 0) as revenue'),
            ])
            ->groupBy('product_name')
            ->orderByDesc('total_orders')
            ->limit($limit)
            ->get();
    }

    // ── Customer Insights ─────────────────────────────────────────────────────

    public function lostCustomerCountFromProfiles(int $merchantId): int
    {
        return CustomerProfile::where('merchant_id', $merchantId)
            ->where('health_status', 'lost')
            ->count();
    }

    public function vipCustomerCount(int $merchantId): int
    {
        return Customer::where('merchant_id', $merchantId)
            ->whereIn('vip_level', BusinessRuleRegistry::SEGMENT_VIP_LEVELS)
            ->count();
    }

    /**
     * Top N customers by delivered order frequency.
     * Returns: customer_id, customer_name, total_orders, total_spending
     */
    public function topCustomersByFrequency(int $merchantId, int $limit = 10): Collection
    {
        return DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->select([
                'customer_id',
                'customer_name',
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('COALESCE(SUM(order_value), 0) as total_spending'),
            ])
            ->groupBy('customer_id', 'customer_name')
            ->orderByDesc('total_orders')
            ->limit($limit)
            ->get();
    }

    // ── Area Metrics ──────────────────────────────────────────────────────────

    /**
     * Per-cluster aggregated metrics across all time (no limit).
     * Returns: cluster, total_orders, revenue, delivered, terminal_cnt, unique_customers
     */
    public function areaMetrics(int $merchantId): Collection
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
                DB::raw('COUNT(CASE WHEN o.status = "delivered" THEN 1 END) as delivered'),
                DB::raw('COUNT(CASE WHEN o.status IN ("delivered", "failed", "cancelled") THEN 1 END) as terminal_cnt'),
                DB::raw('COUNT(DISTINCT o.customer_id) as unique_customers'),
            ])
            ->groupBy('c.cluster')
            ->orderByDesc('revenue')
            ->get();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GROWTH DASHBOARD METHODS — Added for GD V1
    // ═══════════════════════════════════════════════════════════════════════

    // ── Time-Series: Revenue ──────────────────────────────────────────────

    /**
     * Daily revenue series for a date range.
     * Returns: [{date, revenue, orders}] — one row per day that has deliveries.
     */
    public function revenueByDay(int $merchantId, Carbon $from, Carbon $to): Collection
    {
        return DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->whereBetween('delivered_at', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->select([
                DB::raw('DATE(delivered_at) as date'),
                DB::raw('COALESCE(SUM(order_value), 0) as revenue'),
                DB::raw('COUNT(*) as orders'),
            ])
            ->groupByRaw('DATE(delivered_at)')
            ->orderBy('date')
            ->get();
    }

    /**
     * Daily order creation series for a date range.
     * Returns: [{date, total, delivered, failed}]
     */
    public function ordersByDay(int $merchantId, Carbon $from, Carbon $to): Collection
    {
        return DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->whereBetween('order_created_at', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->select([
                DB::raw('DATE(order_created_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(CASE WHEN status = "delivered" THEN 1 END) as delivered'),
                DB::raw('COUNT(CASE WHEN status = "failed" THEN 1 END) as failed'),
            ])
            ->groupByRaw('DATE(order_created_at)')
            ->orderBy('date')
            ->get();
    }

    /**
     * Monthly revenue and orders (last N months).
     * Returns: [{year_month, revenue, orders}] ordered oldest to newest.
     */
    public function revenueByMonth(int $merchantId, int $months = 6): Collection
    {
        $from = Carbon::now()->startOfMonth()->subMonths($months - 1);

        return DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->where('delivered_at', '>=', $from)
            ->select([
                DB::raw('DATE_FORMAT(delivered_at, "%Y-%m") as `year_month`'),
                DB::raw('COALESCE(SUM(order_value), 0) as revenue'),
                DB::raw('COUNT(*) as orders'),
            ])
            ->groupByRaw('DATE_FORMAT(delivered_at, "%Y-%m")')
            ->orderByRaw('`year_month` ASC')
            ->get();
    }

    // ── Revenue by Customer Type ──────────────────────────────────────────

    /**
     * Revenue breakdown by customer_type for a period.
     * Returns: [{customer_type, revenue, orders, unique_customers}]
     */
    public function revenueByCustomerType(int $merchantId, Carbon $from, Carbon $to): Collection
    {
        // Subquery first to resolve customer_type alias, then GROUP BY the plain alias name.
        // Avoids ONLY_FULL_GROUP_BY rejection on MariaDB strict mode servers.
        $inner = DB::table('delivery_orders as o')
            ->leftJoin('customers as c', function ($join) {
                $join->on('o.customer_id', '=', 'c.id')
                     ->whereNull('c.deleted_at');
            })
            ->where('o.merchant_id', $merchantId)
            ->where('o.status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('o.deleted_at')
            ->whereBetween('o.delivered_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->select([
                DB::raw('COALESCE(NULLIF(c.customer_type, ""), "Uncategorized") as ctype'),
                DB::raw('o.order_value'),
                DB::raw('o.id as oid'),
                DB::raw('o.customer_id'),
            ]);

        return DB::table(DB::raw("({$inner->toSql()}) as sub"))
            ->mergeBindings($inner)
            ->select([
                DB::raw('ctype as customer_type'),
                DB::raw('COALESCE(SUM(order_value), 0) as revenue'),
                DB::raw('COUNT(oid) as orders'),
                DB::raw('COUNT(DISTINCT customer_id) as unique_customers'),
            ])
            ->groupBy('ctype')
            ->orderByDesc('revenue')
            ->get();
    }

    // ── Customer Growth & Segmentation ───────────────────────────────────

    /**
     * Customer segment counts from customer_profiles.
     * Returns: {healthy, active, at_risk, dormant, lost, total}
     */
    public function customerSegmentCounts(int $merchantId): array
    {
        $rows = DB::table('customer_profiles')
            ->where('merchant_id', $merchantId)
            ->select([
                'health_status',
                DB::raw('COUNT(*) as cnt'),
            ])
            ->groupBy('health_status')
            ->get()
            ->keyBy('health_status');

        $get = fn(string $key): int => (int) ($rows[$key]->cnt ?? 0);

        return [
            'healthy'  => $get('healthy'),
            'active'   => $get('active'),
            'at_risk'  => $get('at_risk'),
            'dormant'  => $get('dormant'),
            'lost'     => $get('lost'),
            'total'    => $rows->sum('cnt'),
        ];
    }

    /**
     * Customer lifetime value distribution buckets.
     * Returns: {under_100k, k100_500k, k500_1m, over_1m, avg_clv}
     */
    public function clvBuckets(int $merchantId): array
    {
        $rows = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->select([
                'customer_id',
                DB::raw('COALESCE(SUM(order_value), 0) as total_spending'),
            ])
            ->groupBy('customer_id')
            ->get();

        if ($rows->isEmpty()) {
            return ['under_100k' => 0, 'k100_500k' => 0, 'k500_1m' => 0, 'over_1m' => 0, 'avg_clv' => 0.0];
        }

        $buckets = ['under_100k' => 0, 'k100_500k' => 0, 'k500_1m' => 0, 'over_1m' => 0];
        $total   = 0.0;

        foreach ($rows as $row) {
            $v = (float) $row->total_spending;
            $total += $v;
            if ($v < 100_000)        $buckets['under_100k']++;
            elseif ($v < 500_000)    $buckets['k100_500k']++;
            elseif ($v < 1_000_000)  $buckets['k500_1m']++;
            else                     $buckets['over_1m']++;
        }

        $buckets['avg_clv'] = round($total / $rows->count(), 0);
        return $buckets;
    }

    /**
     * Average order frequency for customers with ≥2 orders.
     */
    public function avgOrderFrequency(int $merchantId): float
    {
        $result = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->select([
                'customer_id',
                DB::raw('COUNT(*) as order_count'),
            ])
            ->groupBy('customer_id')
            ->having('order_count', '>=', 2)
            ->get();

        if ($result->isEmpty()) return 0.0;

        return round($result->avg('order_count'), 1);
    }

    /**
     * Monthly new customer acquisition series (last N months).
     * Returns: [{year_month, new_customers}]
     */
    public function customerAcquisitionByMonth(int $merchantId, int $months = 6): Collection
    {
        $from = Carbon::now()->startOfMonth()->subMonths($months - 1);

        return DB::table('customers')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $from)
            ->select([
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as `year_month`'),
                DB::raw('COUNT(*) as new_customers'),
            ])
            ->groupByRaw('DATE_FORMAT(created_at, "%Y-%m")')
            ->orderByRaw('`year_month` ASC')
            ->get();
    }

    // ── Operations Intelligence ───────────────────────────────────────────

    /**
     * Average minutes from order creation to driver assignment (last 30 days).
     * Returns null when no assigned orders found.
     */
    public function avgDispatchMinutes(int $merchantId, int $days = 30): ?float
    {
        $from = Carbon::now()->subDays($days);

        $result = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->whereNotNull('assigned_at')
            ->where('order_created_at', '>=', $from)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, order_created_at, assigned_at)) as avg_min')
            ->value('avg_min');

        return $result !== null ? round((float) $result, 1) : null;
    }

    /**
     * Average minutes from pickup to delivery (last 30 days).
     * Returns null when no matching orders found.
     */
    public function avgDeliveryMinutes(int $merchantId, int $days = 30): ?float
    {
        $from = Carbon::now()->subDays($days);

        $result = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->whereNotNull('picked_up_at')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', $from)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, picked_up_at, delivered_at)) as avg_min')
            ->value('avg_min');

        return $result !== null ? round((float) $result, 1) : null;
    }

    /**
     * Average route quality score from routes in last 30 days.
     * Returns null when no routes with quality_score found.
     */
    public function avgRouteQualityScore(int $merchantId, int $days = 30): ?float
    {
        $from = Carbon::now()->subDays($days);

        $result = DB::table('routes')
            ->where('merchant_id', $merchantId)
            ->whereNotNull('quality_score')
            ->where('route_date', '>=', $from->toDateString())
            ->avg('quality_score');

        return $result !== null ? round((float) $result, 1) : null;
    }

    /**
     * Delivered orders today divided by active driver count.
     */
    public function ordersPerDriver(int $merchantId): float
    {
        $activeDrivers = DB::table('drivers')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->count();

        if ($activeDrivers === 0) return 0.0;

        $ordersToday = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->whereDate('delivered_at', Carbon::today())
            ->count();

        return round($ordersToday / $activeDrivers, 1);
    }

    /**
     * Percentage of active drivers who delivered at least one order today.
     */
    public function driverUtilizationToday(int $merchantId): float
    {
        $activeDrivers = DB::table('drivers')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->count();

        if ($activeDrivers === 0) return 0.0;

        $driversWithDelivery = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', BusinessRuleRegistry::REVENUE_STATUS)
            ->whereNull('deleted_at')
            ->whereDate('delivered_at', Carbon::today())
            ->distinct('driver_id')
            ->count('driver_id');

        return round($driversWithDelivery / $activeDrivers * 100, 1);
    }
}
