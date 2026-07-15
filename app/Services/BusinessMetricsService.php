<?php

namespace App\Services;

use App\Analytics\AnalyticsRepository;
use App\Analytics\BusinessRuleRegistry;
use Carbon\Carbon;

/**
 * High-level Business Intelligence API.
 *
 * This is the single entry point for any module (dashboards, reports,
 * exports, growth features) that needs pre-built KPI metrics.
 * It owns orchestration and response shaping; SQL lives in AnalyticsRepository
 * and thresholds live in BusinessRuleRegistry.
 */
class BusinessMetricsService
{
    public function __construct(
        private readonly AnalyticsRepository $repository,
        private readonly FeatureManager $features,
    ) {}

    // ── Dashboard Sections ────────────────────────────────────────────────────

    /**
     * Revenue, orders, deliveries completed, active drivers, success rate for today.
     */
    public function getOperationsToday(int $merchantId, string $timezone): array
    {
        $today     = Carbon::today($timezone);
        $delivered = $this->repository->deliveredRevenueForDay($merchantId, $today);
        $terminal  = $this->repository->terminalCountsForDay($merchantId, $today);

        $successRate = $terminal['total'] > 0
            ? round(BusinessRuleRegistry::successRate($terminal['delivered'], $terminal['total']) * 100, 1)
            : null;

        return [
            'revenue'              => $delivered['revenue'],
            'orders'               => $this->repository->orderCountForDay($merchantId, $today),
            'deliveries_completed' => $delivered['count'],
            'active_drivers'       => $this->repository->activeDriverCount($merchantId),
            'success_rate'         => $successRate,
        ];
    }

    /**
     * Revenue, orders, averages, and customer growth for the current calendar month.
     */
    public function getBusinessThisMonth(int $merchantId, string $timezone): array
    {
        $from     = Carbon::now($timezone)->startOfMonth();
        $to       = Carbon::now($timezone)->endOfMonth();
        $lastFrom = Carbon::now($timezone)->subMonth()->startOfMonth();
        $lastTo   = Carbon::now($timezone)->subMonth()->endOfMonth();

        $delivered    = $this->repository->deliveredRevenueForPeriod($merchantId, $from, $to);
        $deliveredCnt = $delivered['count'];
        $revenue      = $delivered['revenue'];
        $avgOrderValue = $deliveredCnt > 0 ? round($revenue / $deliveredCnt) : 0;

        $newThisMonth = $this->repository->newCustomersForPeriod($merchantId, $from, $to);
        $newLastMonth = $this->repository->newCustomersForPeriod($merchantId, $lastFrom, $lastTo);

        return [
            'revenue'             => $revenue,
            'orders'              => $this->repository->orderCountForPeriod($merchantId, $from, $to),
            'avg_order_value'     => (float) $avgOrderValue,
            'repeat_customers'    => $this->repository->repeatCustomerCount($merchantId),
            'new_customers'       => $newThisMonth,
            'customer_growth_pct' => BusinessRuleRegistry::growthPct($newThisMonth, $newLastMonth),
        ];
    }

    /**
     * Customer counts: total, new this month, growth %, repeat, dormant.
     * repeat and dormant are null when customer_domain feature is disabled.
     */
    public function getCustomerHealth(int $merchantId, string $timezone): array
    {
        $from     = Carbon::now($timezone)->startOfMonth();
        $to       = Carbon::now($timezone)->endOfMonth();
        $lastFrom = Carbon::now($timezone)->subMonth()->startOfMonth();
        $lastTo   = Carbon::now($timezone)->subMonth()->endOfMonth();

        $newThisMonth = $this->repository->newCustomersForPeriod($merchantId, $from, $to);
        $newLastMonth = $this->repository->newCustomersForPeriod($merchantId, $lastFrom, $lastTo);
        $hasDomain    = $this->features->isEnabled($merchantId, 'customer_domain');

        return [
            'total'          => $this->repository->totalCustomers($merchantId),
            'new_this_month' => $newThisMonth,
            'repeat'         => $hasDomain ? $this->repository->repeatCustomerCountFromProfiles($merchantId) : null,
            'dormant'        => $hasDomain ? $this->repository->dormantCustomerCountFromProfiles($merchantId) : null,
            'growth_pct'     => BusinessRuleRegistry::growthPct($newThisMonth, $newLastMonth),
        ];
    }

    /**
     * Per-cluster order count, revenue, deliveries, and success rate.
     * Sorted by revenue descending, capped at $limit rows.
     */
    public function getClusterSummary(int $merchantId, int $limit = 20): array
    {
        return $this->repository->clusterSummary($merchantId, $limit)
            ->map(fn($row) => [
                'cluster'      => $row->cluster,
                'total_orders' => (int) $row->total_orders,
                'revenue'      => (float) $row->revenue,
                'deliveries'   => (int) $row->deliveries,
                'success_rate' => (int) $row->terminal_cnt > 0
                    ? round(BusinessRuleRegistry::successRate(
                        (int) $row->deliveries,
                        (int) $row->terminal_cnt
                    ) * 100, 1)
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Last N orders in active or terminal states, with driver name.
     */
    public function getRecentActivity(int $merchantId, int $limit = 15): array
    {
        return $this->repository->recentOrders($merchantId, $limit)
            ->map(fn($o) => [
                'id'            => $o->id,
                'order_number'  => $o->order_number,
                'customer_name' => $o->customer_name,
                'status'        => $o->status,
                'driver_name'   => $o->driver?->driver_name,
                'occurred_at'   => $o->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Actionable attention items: delayed deliveries, failed today,
     * customers without GPS, dormant customers, offline drivers.
     * Each item: { type, label, count, severity }
     */
    public function getRequiresAttention(int $merchantId): array
    {
        $items     = [];
        $hasDomain = $this->features->isEnabled($merchantId, 'customer_domain');

        $delayed = $this->repository->delayedDeliveryCount($merchantId);
        if ($delayed > 0) {
            $items[] = [
                'type'     => 'delayed_deliveries',
                'label'    => 'Delayed Deliveries (>' . BusinessRuleRegistry::ATTENTION_DELAYED_HOURS . 'h)',
                'count'    => $delayed,
                'severity' => 'warning',
            ];
        }

        $failedToday = $this->repository->failedTodayCount($merchantId);
        if ($failedToday > 0) {
            $items[] = [
                'type'     => 'failed_today',
                'label'    => 'Failed Deliveries Today',
                'count'    => $failedToday,
                'severity' => 'error',
            ];
        }

        $missingGps = $this->repository->customersWithoutGpsCount($merchantId);
        if ($missingGps > 0) {
            $items[] = [
                'type'     => 'missing_gps',
                'label'    => 'Customers Without GPS',
                'count'    => $missingGps,
                'severity' => 'info',
            ];
        }

        if ($hasDomain) {
            $dormant = $this->repository->dormantCustomerCountFromProfiles($merchantId);
            if ($dormant > 0) {
                $items[] = [
                    'type'     => 'dormant_customers',
                    'label'    => 'Dormant / Lost Customers',
                    'count'    => $dormant,
                    'severity' => 'warning',
                ];
            }
        }

        $offlineDrivers = $this->repository->offlineActiveDrivers($merchantId)->count();
        if ($offlineDrivers > 0) {
            $items[] = [
                'type'     => 'offline_drivers',
                'label'    => 'Offline Drivers',
                'count'    => $offlineDrivers,
                'severity' => 'info',
            ];
        }

        return $items;
    }

    // ── Reusable Period Metrics (for future Operational Intelligence, Reports, Exports) ──

    /**
     * Total delivered revenue for an arbitrary date range.
     */
    public function getRevenueForPeriod(int $merchantId, Carbon $from, Carbon $to): float
    {
        return $this->repository->deliveredRevenueForPeriod($merchantId, $from, $to)['revenue'];
    }

    /**
     * Order funnel (total created, delivered count, revenue) for an arbitrary date range.
     *
     * @return array{total: int, delivered: int, revenue: float}
     */
    public function getOrdersForPeriod(int $merchantId, Carbon $from, Carbon $to): array
    {
        $delivered = $this->repository->deliveredRevenueForPeriod($merchantId, $from, $to);
        return [
            'total'     => $this->repository->orderCountForPeriod($merchantId, $from, $to),
            'delivered' => $delivered['count'],
            'revenue'   => $delivered['revenue'],
        ];
    }

    /**
     * Top customers by total lifetime delivered spending.
     */
    public function getTopCustomers(int $merchantId, int $limit = 10): array
    {
        return $this->repository->topCustomers($merchantId, $limit)
            ->map(fn($r) => [
                'customer_id'    => $r->customer_id,
                'customer_name'  => $r->customer_name,
                'total_orders'   => (int) $r->total_orders,
                'total_spending' => (float) $r->total_spending,
            ])
            ->all();
    }

    // ── BI Workspace Sections (Phase 4.1) ─────────────────────────────────────

    /**
     * 60-second business overview: highlights from every section.
     */
    public function getOverview(int $merchantId, string $timezone): array
    {
        $operationsToday = $this->getOperationsToday($merchantId, $timezone);
        $businessMonth   = $this->getBusinessThisMonth($merchantId, $timezone);
        $customerHealth  = $this->getCustomerHealth($merchantId, $timezone);
        $attention       = $this->getRequiresAttention($merchantId);

        $topCluster  = $this->repository->clusterSummary($merchantId, 1)->first();
        $topCustomer = $this->repository->topCustomers($merchantId, 1)->first();
        $topDriver   = $this->repository->driverRanking($merchantId, 1)->first();
        $topProduct  = $this->repository->productRanking($merchantId, 1)->first();

        return [
            'operations_today'    => $operationsToday,
            'business_this_month' => $businessMonth,
            'customer_health'     => $customerHealth,
            'top_cluster'         => $topCluster ? [
                'cluster' => $topCluster->cluster,
                'revenue' => (float) $topCluster->revenue,
                'orders'  => (int) $topCluster->total_orders,
            ] : null,
            'top_customer'        => $topCustomer ? [
                'customer_name' => $topCustomer->customer_name,
                'spending'      => (float) $topCustomer->total_spending,
                'orders'        => (int) $topCustomer->total_orders,
            ] : null,
            'top_driver'          => $topDriver ? [
                'driver_name' => $topDriver->driver_name,
                'deliveries'  => (int) $topDriver->completed,
                'revenue'     => (float) $topDriver->revenue,
            ] : null,
            'top_product'         => $topProduct ? [
                'product_name' => $topProduct->product_name,
                'orders'       => (int) $topProduct->total_orders,
            ] : null,
            'requires_attention'  => $attention,
        ];
    }

    /**
     * Deep customer breakdown: counts, top by revenue, top by frequency.
     */
    public function getCustomerInsights(int $merchantId, string $timezone): array
    {
        $from      = Carbon::now($timezone)->startOfMonth();
        $to        = Carbon::now($timezone)->endOfMonth();
        $hasDomain = $this->features->isEnabled($merchantId, 'customer_domain');

        return [
            'total'          => $this->repository->totalCustomers($merchantId),
            'new_this_month' => $this->repository->newCustomersForPeriod($merchantId, $from, $to),
            'repeat'         => $this->repository->repeatCustomerCount($merchantId),
            'vip'            => $this->repository->vipCustomerCount($merchantId),
            'dormant'        => $hasDomain ? $this->repository->dormantCustomerCountFromProfiles($merchantId) : null,
            'lost'           => $hasDomain ? $this->repository->lostCustomerCountFromProfiles($merchantId) : null,
            'without_gps'    => $this->repository->customersWithoutGpsCount($merchantId),
            'top_by_revenue' => $this->repository->topCustomers($merchantId, 10)
                ->map(fn($r) => [
                    'customer_id'    => $r->customer_id,
                    'customer_name'  => $r->customer_name,
                    'total_orders'   => (int) $r->total_orders,
                    'total_spending' => (float) $r->total_spending,
                ])->values()->all(),
            'top_by_frequency' => $this->repository->topCustomersByFrequency($merchantId, 10)
                ->map(fn($r) => [
                    'customer_id'    => $r->customer_id,
                    'customer_name'  => $r->customer_name,
                    'total_orders'   => (int) $r->total_orders,
                    'total_spending' => (float) $r->total_spending,
                ])->values()->all(),
        ];
    }

    /**
     * Operations deep dive: queue sizes, delays, success rate, driver status.
     */
    public function getOperationsInsights(int $merchantId, string $timezone): array
    {
        $today    = Carbon::today($timezone);
        $terminal = $this->repository->terminalCountsForDay($merchantId, $today);

        $successRate = $terminal['total'] > 0
            ? round(BusinessRuleRegistry::successRate($terminal['delivered'], $terminal['total']) * 100, 1)
            : null;

        return [
            'pending_orders'     => $this->repository->pendingOrderCount($merchantId),
            'pending_assignment' => $this->repository->pendingAssignmentCount($merchantId),
            'delayed_deliveries' => $this->repository->delayedDeliveryCount($merchantId),
            'failed_today'       => $this->repository->failedTodayCount($merchantId),
            'total_orders_today' => $this->repository->orderCountForDay($merchantId, $today),
            'delivered_today'    => $terminal['delivered'],
            'success_rate_today' => $successRate,
            'active_drivers'     => $this->repository->activeDriverCount($merchantId),
            'offline_drivers'    => $this->repository->offlineActiveDrivers($merchantId)
                ->map(fn($d) => ['id' => $d->id, 'driver_name' => $d->driver_name])
                ->values()->all(),
        ];
    }

    /**
     * Driver ranking by completed deliveries with success rate.
     */
    public function getDriverInsights(int $merchantId): array
    {
        $ranking = $this->repository->driverRanking($merchantId, 20)
            ->map(function ($row) {
                $successRate = (int) $row->total_assigned > 0
                    ? round(
                        BusinessRuleRegistry::successRate((int) $row->completed, (int) $row->total_assigned) * 100,
                        1
                    )
                    : null;

                return [
                    'driver_id'      => $row->driver_id,
                    'driver_name'    => $row->driver_name,
                    'status'         => $row->status,
                    'completed'      => (int) $row->completed,
                    'failed'         => (int) $row->failed,
                    'revenue'        => (float) $row->revenue,
                    'total_assigned' => (int) $row->total_assigned,
                    'success_rate'   => $successRate,
                ];
            })->values()->all();

        return [
            'ranking'        => $ranking,
            'total_drivers'  => count($ranking),
            'offline_drivers' => $this->repository->offlineActiveDrivers($merchantId)
                ->map(fn($d) => ['id' => $d->id, 'driver_name' => $d->driver_name])
                ->values()->all(),
        ];
    }

    /**
     * Cluster/branch breakdown: revenue, orders, success rate, avg order value.
     */
    public function getBranchInsights(int $merchantId): array
    {
        $clusters = $this->repository->clusterSummary($merchantId, 20)
            ->map(function ($row) {
                $successRate = (int) $row->terminal_cnt > 0
                    ? round(
                        BusinessRuleRegistry::successRate((int) $row->deliveries, (int) $row->terminal_cnt) * 100,
                        1
                    )
                    : null;
                $avgOrder = (int) $row->deliveries > 0
                    ? round((float) $row->revenue / (int) $row->deliveries)
                    : 0;

                return [
                    'cluster'      => $row->cluster,
                    'total_orders' => (int) $row->total_orders,
                    'revenue'      => (float) $row->revenue,
                    'deliveries'   => (int) $row->deliveries,
                    'success_rate' => $successRate,
                    'avg_order'    => (float) $avgOrder,
                ];
            })->values()->all();

        return [
            'clusters'    => $clusters,
            'top_cluster' => !empty($clusters) ? $clusters[0] : null,
        ];
    }

    /**
     * Product ranking by order count and revenue.
     * has_data is false when no product_name data exists.
     */
    public function getProductInsights(int $merchantId): array
    {
        $raw = $this->repository->productRanking($merchantId, 20);

        $byOrders = $raw->map(fn($r) => [
            'product_name' => $r->product_name,
            'total_orders' => (int) $r->total_orders,
            'delivered'    => (int) $r->delivered,
            'revenue'      => (float) $r->revenue,
        ])->values()->all();

        $byRevenue = collect($byOrders)->sortByDesc('revenue')->values()->all();

        return [
            'has_data'    => count($byOrders) > 0,
            'top_selling' => $byOrders,
            'top_revenue' => $byRevenue,
        ];
    }

    /**
     * Geographic area breakdown: orders, revenue, unique customers per cluster.
     */
    public function getAreaInsights(int $merchantId): array
    {
        $areas = $this->repository->areaMetrics($merchantId)
            ->map(function ($row) {
                $successRate = (int) $row->terminal_cnt > 0
                    ? round(
                        BusinessRuleRegistry::successRate((int) $row->delivered, (int) $row->terminal_cnt) * 100,
                        1
                    )
                    : null;

                return [
                    'cluster'          => $row->cluster,
                    'total_orders'     => (int) $row->total_orders,
                    'revenue'          => (float) $row->revenue,
                    'delivered'        => (int) $row->delivered,
                    'success_rate'     => $successRate,
                    'unique_customers' => (int) $row->unique_customers,
                ];
            })->values()->all();

        $topArea = collect($areas)->sortByDesc('revenue')->first();

        return [
            'areas'    => $areas,
            'top_area' => $topArea,
        ];
    }
}
