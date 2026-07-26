<?php

namespace App\Services\Growth;

use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Services\DashboardLabelService;
use Carbon\Carbon;

/**
 * Aggregates growth metrics for a single merchant.
 *
 * This service is the data contract the Growth Dashboard will consume.
 * It abstracts the query layer (GrowthMetricsRepository) and applies
 * merchant-specific configuration (DashboardLabelService) to the output.
 *
 * Phase 6.2 — Architecture stub. The snapshot() and trends() methods
 * define the expected response shape. Implement as Growth Dashboard is built.
 *
 * Usage (future Growth Dashboard controller):
 *   $svc = app(MerchantGrowthService::class);
 *   $data = $svc->snapshot($merchant, now()->subDays(30), now());
 */
class MerchantGrowthService
{
    public function __construct(
        private readonly GrowthMetricsRepository $repo,
    ) {}

    /**
     * Point-in-time snapshot of key growth indicators for one merchant.
     *
     * @return array{
     *   period: array{from: string, to: string},
     *   labels: array<string, string>,
     *   metrics: array{
     *     total_orders: int,
     *     delivered_orders: int,
     *     success_rate: float,
     *     revenue: float,
     *     new_customers: int,
     *     repeat_buyers: int,
     *   }
     * }
     */
    public function snapshot(Merchant $merchant, Carbon $from, Carbon $to): array
    {
        $settings = MerchantSetting::firstOrCreate(['merchant_id' => $merchant->id]);
        $labels   = DashboardLabelService::fromSettings($settings);

        $totalOrders     = $this->repo->totalOrdersInPeriod($from, $to, $merchant->id);
        $deliveredOrders = $this->repo->deliveredOrdersInPeriod($from, $to, $merchant->id);

        return [
            'period'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'labels'  => $labels->all(),
            'metrics' => [
                'total_orders'     => $totalOrders,
                'delivered_orders' => $deliveredOrders,
                'success_rate'     => $totalOrders > 0
                    ? round($deliveredOrders / $totalOrders * 100, 1)
                    : 0.0,
                'revenue'          => $this->repo->revenueInPeriod($from, $to, $merchant->id),
                'new_customers'    => $this->repo->newCustomersInPeriod($from, $to, $merchant->id),
                'repeat_buyers'    => $this->repo->repeatBuyersInPeriod($from, $to, $merchant->id),
            ],
        ];
    }

    /**
     * Weekly trend series for charts (last N weeks).
     * Returns an array of snapshot-like objects, one per week.
     *
     * Phase 6.2 — stub only. Implement when Growth Dashboard chart is built.
     */
    public function weeklyTrend(Merchant $merchant, int $weeks = 8): array
    {
        // TODO: iterate over $weeks windows, call snapshot() for each, return series
        return [];
    }
}
