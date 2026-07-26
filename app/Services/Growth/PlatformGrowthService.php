<?php

namespace App\Services\Growth;

use Carbon\Carbon;

/**
 * Aggregates growth metrics across ALL merchants (super-admin view).
 *
 * Intended for the Platform Admin Growth Dashboard — a bird's-eye view
 * of how the Hontal platform itself is growing.
 *
 * Metrics here are always cross-tenant (no MerchantScope).
 * Never expose individual merchant PII in aggregated responses.
 *
 * Phase 6.2 — Architecture stub. Define shape; implement per-method
 * as the Super Admin Growth Dashboard is built.
 *
 * Usage (future super-admin controller):
 *   $data = app(PlatformGrowthService::class)->snapshot(now()->subDays(30), now());
 */
class PlatformGrowthService
{
    public function __construct(
        private readonly GrowthMetricsRepository $repo,
    ) {}

    /**
     * Platform-wide snapshot for a given period.
     *
     * @return array{
     *   period: array{from: string, to: string},
     *   merchants: array{new: int, active: int},
     *   orders: array{total: int, delivered: int, success_rate: float},
     *   gmv: float,
     * }
     */
    public function snapshot(Carbon $from, Carbon $to): array
    {
        $totalOrders     = $this->repo->totalOrdersInPeriod($from, $to);
        $deliveredOrders = $this->repo->deliveredOrdersInPeriod($from, $to);

        return [
            'period'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'merchants' => [
                'new'    => $this->repo->newMerchantsInPeriod($from, $to, includeTrial: false),
                'active' => $this->repo->activeMerchantsInPeriod($from, $to),
            ],
            'orders'    => [
                'total'        => $totalOrders,
                'delivered'    => $deliveredOrders,
                'success_rate' => $totalOrders > 0
                    ? round($deliveredOrders / $totalOrders * 100, 1)
                    : 0.0,
            ],
            'gmv'       => $this->repo->revenueInPeriod($from, $to),
        ];
    }

    /**
     * Monthly trend for platform-level charts (last N months).
     *
     * Phase 6.2 — stub only. Implement when Super Admin Growth Dashboard is built.
     */
    public function monthlyTrend(int $months = 12): array
    {
        // TODO: iterate over $months windows, call snapshot() for each, return series
        return [];
    }

    /**
     * Top N merchants by order volume in the period.
     *
     * Phase 6.2 — stub only.
     */
    public function topMerchantsByOrders(Carbon $from, Carbon $to, int $limit = 10): array
    {
        // TODO: query delivery_orders grouped by merchant_id, join merchants
        return [];
    }
}
