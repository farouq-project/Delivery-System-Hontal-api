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
}
