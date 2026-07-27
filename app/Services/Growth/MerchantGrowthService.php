<?php

namespace App\Services\Growth;

use App\Analytics\AnalyticsRepository;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Services\DashboardLabelService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates growth metrics for a single merchant.
 *
 * This service is the data contract the Growth Dashboard consumes.
 * It abstracts the query layer (GrowthMetricsRepository + AnalyticsRepository)
 * and applies merchant-specific configuration (DashboardLabelService) to the output.
 */
class MerchantGrowthService
{
    public function __construct(
        private readonly GrowthMetricsRepository $repo,
        private readonly AnalyticsRepository $analytics,
    ) {}

    /**
     * Point-in-time snapshot of key growth indicators for one merchant.
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
     * Weekly trend series for the Sales Dashboard (last N weeks).
     * Returns: [{week_start, week_end, week_label, revenue, orders, delivered, new_customers, success_rate}]
     */
    public function weeklyTrend(Merchant $merchant, int $weeks = 8): array
    {
        return Cache::remember(
            "growth.sales.weekly.{$merchant->id}",
            now()->addMinutes(10),
            function () use ($merchant, $weeks) {
                $series = [];
                for ($i = $weeks - 1; $i >= 0; $i--) {
                    $from = Carbon::now()->startOfWeek()->subWeeks($i);
                    $to   = $from->copy()->endOfWeek();

                    $total     = $this->repo->totalOrdersInPeriod($from, $to, $merchant->id);
                    $delivered = $this->repo->deliveredOrdersInPeriod($from, $to, $merchant->id);

                    $series[] = [
                        'week_start'    => $from->toDateString(),
                        'week_end'      => $to->toDateString(),
                        'week_label'    => $from->format('d M'),
                        'revenue'       => $this->repo->revenueInPeriod($from, $to, $merchant->id),
                        'orders'        => $total,
                        'delivered'     => $delivered,
                        'new_customers' => $this->repo->newCustomersInPeriod($from, $to, $merchant->id),
                        'success_rate'  => $total > 0 ? round($delivered / $total * 100, 1) : 0.0,
                    ];
                }
                return $series;
            }
        );
    }

    /**
     * Monthly trend series for charts (last N months).
     * Returns: [{year_month, month_label, revenue, orders, new_customers}]
     */
    public function monthlyTrend(Merchant $merchant, int $months = 6): array
    {
        return Cache::remember(
            "growth.sales.monthly.{$merchant->id}",
            now()->addMinutes(15),
            function () use ($merchant, $months) {
                $revRows  = $this->analytics->revenueByMonth($merchant->id, $months);
                $custRows = $this->analytics->customerAcquisitionByMonth($merchant->id, $months);

                $revMap  = $revRows->keyBy('year_month');
                $custMap = $custRows->keyBy('year_month');

                $series = [];
                for ($i = $months - 1; $i >= 0; $i--) {
                    $month   = Carbon::now()->startOfMonth()->subMonths($i);
                    $key     = $month->format('Y-m');
                    $revRow  = $revMap->get($key);
                    $custRow = $custMap->get($key);

                    $series[] = [
                        'year_month'    => $key,
                        'month_label'   => $month->format('M Y'),
                        'revenue'       => (float) ($revRow->revenue ?? 0),
                        'orders'        => (int) ($revRow->orders ?? 0),
                        'new_customers' => (int) ($custRow->new_customers ?? 0),
                    ];
                }
                return $series;
            }
        );
    }

    /**
     * Compare current month-to-date against the equivalent prior-month window.
     * Returns KPI comparison data for the Executive Overview.
     */
    public function monthComparison(Merchant $merchant): array
    {
        $today  = Carbon::now();
        $dayNum = (int) $today->format('j');

        $curFrom  = $today->copy()->startOfMonth();
        $curTo    = $today->copy()->endOfDay();
        $prevFrom = $today->copy()->subMonth()->startOfMonth();
        $prevTo   = $prevFrom->copy()->addDays($dayNum - 1)->endOfDay();

        $cur  = $this->snapshot($merchant, $curFrom, $curTo);
        $prev = $this->snapshot($merchant, $prevFrom, $prevTo);

        $cm = $cur['metrics'];
        $pm = $prev['metrics'];

        $growthPct = fn(float $c, float $p): ?float =>
            $p > 0 ? round(($c - $p) / $p * 100, 1) : ($c > 0 ? 100.0 : null);

        return [
            'period' => [
                'current_label' => $today->format('F Y') . ' MTD',
                'prior_label'   => $prevFrom->format('F Y') . " (day 1–{$dayNum})",
            ],
            'revenue' => [
                'current'    => $cm['revenue'],
                'prior'      => $pm['revenue'],
                'growth_pct' => $growthPct($cm['revenue'], $pm['revenue']),
            ],
            'orders' => [
                'current'    => $cm['total_orders'],
                'prior'      => $pm['total_orders'],
                'growth_pct' => $growthPct((float) $cm['total_orders'], (float) $pm['total_orders']),
            ],
            'delivered' => [
                'current'    => $cm['delivered_orders'],
                'prior'      => $pm['delivered_orders'],
                'growth_pct' => $growthPct((float) $cm['delivered_orders'], (float) $pm['delivered_orders']),
            ],
            'new_customers' => [
                'current'    => $cm['new_customers'],
                'prior'      => $pm['new_customers'],
                'growth_pct' => $growthPct((float) $cm['new_customers'], (float) $pm['new_customers']),
            ],
            'success_rate' => [
                'current' => $cm['success_rate'],
                'prior'   => $pm['success_rate'],
                'delta'   => round($cm['success_rate'] - $pm['success_rate'], 1),
            ],
        ];
    }
}
