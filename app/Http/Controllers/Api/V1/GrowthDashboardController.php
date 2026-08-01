<?php

namespace App\Http\Controllers\Api\V1;

use App\Analytics\AnalyticsRepository;
use App\Analytics\BusinessRuleRegistry;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Customer;
use App\Models\MerchantGoal;
use App\Services\CustomerDomain\CustomerHealthService;
use App\Services\FeatureManager;
use App\Services\Growth\BusinessHealthScoreService;
use App\Services\Growth\MarketingCampaignService;
use App\Services\Growth\MerchantGrowthService;
use App\Services\Growth\GoalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GrowthDashboardController extends Controller
{
    public function __construct(
        private readonly FeatureManager $features,
        private readonly AnalyticsRepository $analytics,
        private readonly MerchantGrowthService $growth,
        private readonly BusinessHealthScoreService $health,
        private readonly MarketingCampaignService $marketing,
        private readonly GoalService $goals,
    ) {}

    // ── Guard ─────────────────────────────────────────────────────────────

    private function guard(Request $request): ?JsonResponse
    {
        $user       = $request->user();
        $allowedRoles = ['merchant_owner', 'developer', 'super_admin'];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($user->role, ['super_admin', 'developer'])) {
            if (!$this->features->isEnabled($user->merchant_id, 'business_intelligence')) {
                return response()->json(['message' => 'Business Growth is not enabled for your account.'], 403);
            }
        }

        return null;
    }

    private function merchantId(Request $request): int
    {
        $user = $request->user();
        if (in_array($user->role, ['super_admin', 'developer']) && $request->has('merchant_id')) {
            return (int) $request->query('merchant_id');
        }
        return (int) $user->merchant_id;
    }

    private function merchantModel(Request $request): Merchant
    {
        $user = $request->user();
        if (in_array($user->role, ['super_admin', 'developer']) && $request->has('merchant_id')) {
            return Merchant::findOrFail((int) $request->query('merchant_id'));
        }
        return $user->merchant;
    }

    // ── Module 1: Executive Overview ──────────────────────────────────────

    public function executive(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $merchantId = $this->merchantId($request);
        $merchant   = $this->merchantModel($request);

        $healthScore   = $this->health->getScore($merchantId);
        $comparison    = $this->growth->monthComparison($merchant);
        $goalProgress  = $this->goals->getProgress($merchantId);
        $activeGoals   = array_filter($goalProgress, fn($g) => $g['is_active']);

        return response()->json([
            'data' => [
                'health_score'    => $healthScore,
                'month_comparison'=> $comparison,
                'active_goals'    => array_values($activeGoals),
            ],
        ]);
    }

    // ── Module 2: Sales Dashboard ─────────────────────────────────────────

    public function sales(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $merchantId = $this->merchantId($request);
        $merchant   = $this->merchantModel($request);

        $period = $request->query('period', 'monthly'); // weekly | monthly

        $trend = $period === 'weekly'
            ? $this->growth->weeklyTrend($merchant, 8)
            : $this->growth->monthlyTrend($merchant, 6);

        // Revenue by customer type — current month
        $monthStart = Carbon::now()->startOfMonth()->startOfDay();
        $monthEnd   = Carbon::now()->endOfDay();

        // Store as plain array to avoid PHP object deserialization issues in file cache.
        try {
            $byCustomerType = Cache::remember(
                "growth.cust_type.{$merchantId}",
                now()->addMinutes(10),
                fn() => $this->analytics->revenueByCustomerType($merchantId, $monthStart, $monthEnd)->values()->all()
            );
        } catch (\Throwable $e) {
            $byCustomerType = [];
        }

        // Top performers (all-time, cached)
        $topCustomers = Cache::remember(
            "bi.ranking.customers.{$merchantId}",
            now()->addMinutes(10),
            fn() => $this->analytics->topCustomers($merchantId, 5)
        );

        $topProducts = Cache::remember(
            "bi.ranking.products.{$merchantId}",
            now()->addMinutes(10),
            fn() => $this->analytics->productRanking($merchantId, 5)
        );

        $topAreas = Cache::remember(
            "bi.ranking.areas.{$merchantId}",
            now()->addMinutes(10),
            fn() => $this->analytics->areaMetrics($merchantId)
        );

        return response()->json([
            'data' => [
                'period'           => $period,
                'trend'            => $trend,
                'by_customer_type' => $byCustomerType,
                'top_customers'    => $topCustomers->values(),
                'top_products'     => $topProducts->values(),
                'top_areas'        => $topAreas->take(5)->values(),
            ],
        ]);
    }

    // ── Module 4: Customer Growth ─────────────────────────────────────────

    public function customers(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $merchantId = $this->merchantId($request);

        // Segment distribution (cached)
        $segments = Cache::remember(
            "growth.cust.segments.{$merchantId}",
            now()->addMinutes(15),
            fn() => $this->analytics->customerSegmentCounts($merchantId)
        );

        // CLV distribution (cached)
        $clv = Cache::remember(
            "growth.cust.clv.{$merchantId}",
            now()->addMinutes(15),
            fn() => $this->analytics->clvBuckets($merchantId)
        );

        // Purchase frequency
        $avgFrequency = Cache::remember(
            "growth.cust.freq.{$merchantId}",
            now()->addMinutes(15),
            fn() => $this->analytics->avgOrderFrequency($merchantId)
        );

        // Monthly acquisition trend (6 months)
        $acquisitionTrend = Cache::remember(
            "growth.cust.acq.{$merchantId}",
            now()->addMinutes(15),
            fn() => $this->analytics->customerAcquisitionByMonth($merchantId, 6)->values()
        );

        // VIP customers
        $vipCustomers = Cache::remember(
            "growth.cust.vip.{$merchantId}",
            now()->addMinutes(15),
            fn() => Customer::where('merchant_id', $merchantId)
                ->whereIn('vip_level', BusinessRuleRegistry::SEGMENT_VIP_LEVELS)
                ->select(['id', 'customer_name', 'phone', 'vip_level', 'cluster', 'last_order_at', 'total_orders'])
                ->orderByDesc('total_orders')
                ->limit(20)
                ->get()
        );

        // Dormant customers (from customer_profiles)
        $dormantCount = ($segments['dormant'] ?? 0) + ($segments['lost'] ?? 0);

        // Top customers by value (all-time)
        $topByValue = Cache::remember(
            "bi.ranking.customers.{$merchantId}",
            now()->addMinutes(10),
            fn() => $this->analytics->topCustomers($merchantId, 10)
        );

        // Top by frequency
        $topByFreq = Cache::remember(
            "bi.ranking.cust_freq.{$merchantId}",
            now()->addMinutes(10),
            fn() => $this->analytics->topCustomersByFrequency($merchantId, 10)
        );

        return response()->json([
            'data' => [
                'segments'          => $segments,
                'clv'               => $clv,
                'avg_order_frequency' => $avgFrequency,
                'acquisition_trend' => $acquisitionTrend,
                'vip_customers'     => $vipCustomers->values(),
                'dormant_count'     => $dormantCount,
                'top_by_value'      => $topByValue->values(),
                'top_by_frequency'  => $topByFreq->values(),
            ],
        ]);
    }

    // ── Module 3: Operations Metrics ─────────────────────────────────────

    public function operations(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $merchantId = $this->merchantId($request);

        return response()->json([
            'data' => [
                'avg_dispatch_min'   => $this->analytics->avgDispatchMinutes($merchantId),
                'avg_delivery_min'   => $this->analytics->avgDeliveryMinutes($merchantId),
                'avg_route_score'    => $this->analytics->avgRouteQualityScore($merchantId),
                'orders_per_driver'  => $this->analytics->ordersPerDriver($merchantId),
                'driver_utilization' => $this->analytics->driverUtilizationToday($merchantId),
            ],
        ]);
    }

    // ── Module 6: Executive Reports ───────────────────────────────────────

    public function reports(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $merchantId = $this->merchantId($request);
        $merchant   = $this->merchantModel($request);
        $period     = $request->query('period', 'monthly'); // monthly | quarterly | yearly

        [$from, $to, $periodLabel] = $this->resolvePeriod($period);

        $snapshot     = $this->growth->snapshot($merchant, $from, $to);
        $comparison   = $this->growth->monthComparison($merchant);
        $healthScore  = $this->health->getScore($merchantId);
        $goalProgress = $this->goals->getProgress($merchantId);
        $marketingAgg = $this->marketing->getAggregateMetrics($merchantId);

        $topCustomers = $this->analytics->topCustomers($merchantId, 5);
        $topProducts  = $this->analytics->productRanking($merchantId, 5);
        $segments     = $this->analytics->customerSegmentCounts($merchantId);

        $recommendations = $this->generateRecommendations($snapshot, $comparison, $healthScore, $segments);

        return response()->json([
            'data' => [
                'period'          => $periodLabel,
                'generated_at'    => now()->toDateTimeString(),
                'executive_summary' => [
                    'health_score'    => $healthScore,
                    'revenue'         => $snapshot['metrics']['revenue'],
                    'orders'          => $snapshot['metrics']['total_orders'],
                    'success_rate'    => $snapshot['metrics']['success_rate'],
                    'new_customers'   => $snapshot['metrics']['new_customers'],
                ],
                'sales' => [
                    'metrics'       => $snapshot['metrics'],
                    'comparison'    => $comparison,
                    'top_products'  => $topProducts->values(),
                    'top_customers' => $topCustomers->values(),
                ],
                'marketing' => $marketingAgg,
                'customers' => [
                    'segments'   => $segments,
                    'top_by_value' => $topCustomers->values(),
                ],
                'operations' => [
                    'success_rate'     => $snapshot['metrics']['success_rate'],
                    'avg_dispatch_min' => $this->analytics->avgDispatchMinutes($merchantId),
                    'avg_delivery_min' => $this->analytics->avgDeliveryMinutes($merchantId),
                    'avg_route_score'  => $this->analytics->avgRouteQualityScore($merchantId),
                ],
                'goals'           => $goalProgress,
                'recommendations' => $recommendations,
                'next_priorities' => $this->generatePriorities($healthScore),
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function resolvePeriod(string $period): array
    {
        return match ($period) {
            'quarterly' => [
                Carbon::now()->startOfQuarter()->startOfDay(),
                Carbon::now()->endOfDay(),
                'Q' . Carbon::now()->quarter . ' ' . Carbon::now()->year,
            ],
            'yearly' => [
                Carbon::now()->startOfYear()->startOfDay(),
                Carbon::now()->endOfDay(),
                (string) Carbon::now()->year,
            ],
            default => [
                Carbon::now()->startOfMonth()->startOfDay(),
                Carbon::now()->endOfDay(),
                Carbon::now()->format('F Y') . ' (Month-to-Date)',
            ],
        };
    }

    /**
     * Rule-based recommendation engine — no AI.
     * Generates actionable insights from business metrics.
     */
    private function generateRecommendations(
        array $snapshot,
        array $comparison,
        array $healthScore,
        array $segments,
    ): array {
        $recs  = [];
        $m     = $snapshot['metrics'];
        $total = $segments['total'];

        // Success rate
        if ($m['success_rate'] < 70) {
            $recs[] = [
                'priority' => 'high',
                'category' => 'operations',
                'text'     => "Delivery success rate is {$m['success_rate']}% — review failed order reasons and consider driver coaching.",
            ];
        }

        // Revenue decline
        $revGrowth = $comparison['revenue']['growth_pct'];
        if ($revGrowth !== null && $revGrowth < -10) {
            $abs = abs($revGrowth);
            $recs[] = [
                'priority' => 'high',
                'category' => 'sales',
                'text'     => "Revenue is down {$abs}% vs prior month. Review pricing, order quality, and confirm no major customer churn.",
            ];
        }

        // New customer decline
        $custGrowth = $comparison['new_customers']['growth_pct'];
        if ($custGrowth !== null && $custGrowth < 0) {
            $recs[] = [
                'priority' => 'medium',
                'category' => 'marketing',
                'text'     => "New customer acquisition is declining. Consider launching a referral campaign or promotion.",
            ];
        }

        // High dormancy
        if ($total > 0) {
            $dormantPct = round(($segments['dormant'] + $segments['lost']) / $total * 100, 0);
            if ($dormantPct > 30) {
                $recs[] = [
                    'priority' => 'medium',
                    'category' => 'customer',
                    'text'     => "{$dormantPct}% of customers are dormant or lost. A re-engagement campaign via WhatsApp could recover revenue.",
                ];
            }
        }

        // Low health score
        if ($healthScore['score'] < 40) {
            $recs[] = [
                'priority' => 'high',
                'category' => 'executive',
                'text'     => "Business Health Score is {$healthScore['score']}/100 ({$healthScore['grade_label']}). Immediate attention needed on weak pillars.",
            ];
        }

        // No goals set
        $goalsScore = collect($healthScore['pillars'])->firstWhere('name', 'goals');
        if ($goalsScore && $goalsScore['value'] === null) {
            $recs[] = [
                'priority' => 'low',
                'category' => 'goals',
                'text'     => "No active goals set. Setting monthly revenue and order targets helps track business momentum.",
            ];
        }

        return $recs;
    }

    private function generatePriorities(array $healthScore): array
    {
        $priorities = [];
        $pillars    = collect($healthScore['pillars'])->sortBy('score');

        foreach ($pillars->take(3) as $pillar) {
            $priorities[] = match ($pillar['name']) {
                'revenue'    => "Grow monthly revenue — track daily orders and avg order value.",
                'customer'   => "Improve customer health — re-engage dormant customers, grow active base.",
                'operations' => "Improve delivery success rate — review failed orders, strengthen driver routing.",
                'growth'     => "Accelerate customer acquisition — run campaigns, track lead sources.",
                'goals'      => "Set measurable goals — define monthly targets for revenue and orders.",
                default      => "Focus on {$pillar['name']} performance.",
            };
        }

        return $priorities;
    }
}
