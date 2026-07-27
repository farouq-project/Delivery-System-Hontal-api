<?php

namespace App\Services\Growth;

use App\Analytics\AnalyticsRepository;
use App\Analytics\BusinessRuleRegistry;
use App\Models\MerchantGoal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GoalService
{
    public function __construct(
        private readonly AnalyticsRepository $analytics,
        private readonly GrowthMetricsRepository $repo,
    ) {}

    public function list(int $merchantId): Collection
    {
        return MerchantGoal::where('merchant_id', $merchantId)
            ->orderByDesc('period_start')
            ->get();
    }

    public function create(int $merchantId, array $data): MerchantGoal
    {
        return MerchantGoal::create(array_merge($data, ['merchant_id' => $merchantId]));
    }

    public function update(MerchantGoal $goal, array $data): MerchantGoal
    {
        $goal->update($data);
        return $goal->fresh();
    }

    public function delete(MerchantGoal $goal): void
    {
        $goal->delete();
    }

    /**
     * Returns all goals with their current progress against live actuals.
     *
     * @return array[]{goal, target, current, pct, status, is_active}
     */
    public function getProgress(int $merchantId): array
    {
        $goals = $this->list($merchantId);

        return $goals->map(function (MerchantGoal $goal) use ($merchantId) {
            $current = $this->fetchActual($merchantId, $goal->metric, $goal->period_start, $goal->period_end);
            $pct     = $goal->target_value > 0
                ? round($current / $goal->target_value * 100, 1)
                : 0.0;

            return [
                'id'           => $goal->id,
                'metric'       => $goal->metric,
                'target_value' => $goal->target_value,
                'current'      => $current,
                'pct'          => min($pct, 100.0), // cap display at 100%
                'raw_pct'      => $pct,              // actual % for over-achievement display
                'period_type'  => $goal->period_type,
                'period_start' => $goal->period_start->toDateString(),
                'period_end'   => $goal->period_end->toDateString(),
                'notes'        => $goal->notes,
                'is_active'    => $goal->isActive(),
                'status'       => $this->goalStatus($pct, $goal->isActive()),
            ];
        })->values()->all();
    }

    // ── Actuals fetcher ───────────────────────────────────────────────────

    private function fetchActual(int $merchantId, string $metric, Carbon $from, Carbon $to): float
    {
        $f = $from->copy()->startOfDay();
        $t = $to->copy()->endOfDay();

        return match ($metric) {
            'revenue'       => $this->repo->revenueInPeriod($f, $t, $merchantId),
            'orders'        => (float) $this->repo->totalOrdersInPeriod($f, $t, $merchantId),
            'new_customers' => (float) $this->repo->newCustomersInPeriod($f, $t, $merchantId),
            'customers'     => (float) $this->analytics->totalCustomers($merchantId),
            'success_rate'  => $this->fetchSuccessRate($merchantId, $f, $t),
            default         => 0.0,
        };
    }

    private function fetchSuccessRate(int $merchantId, Carbon $from, Carbon $to): float
    {
        $row = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['delivered', 'failed', 'cancelled'])
            ->whereBetween('order_created_at', [$from, $to])
            ->selectRaw('COUNT(*) as total, COUNT(CASE WHEN status = "delivered" THEN 1 END) as delivered')
            ->first();

        if (!$row || $row->total == 0) return 0.0;
        return round($row->delivered / $row->total * 100, 1);
    }

    private function goalStatus(float $pct, bool $isActive): string
    {
        if (!$isActive) return 'ended';
        if ($pct >= 100) return 'achieved';
        if ($pct >= 80)  return 'on_track';
        if ($pct >= 50)  return 'in_progress';
        return 'behind';
    }
}
