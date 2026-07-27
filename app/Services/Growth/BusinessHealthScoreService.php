<?php

namespace App\Services\Growth;

use App\Analytics\AnalyticsRepository;
use App\Analytics\BusinessRuleRegistry;
use App\Services\Growth\MerchantGrowthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Computes a 0–100 Business Health Score for a merchant.
 *
 * Five equal pillars, 20 pts each:
 *   Revenue   — month-over-month revenue growth trend
 *   Customer  — ratio of healthy + active customers to total
 *   Operations — delivery success rate (last 30 days)
 *   Growth    — new customer acquisition growth vs prior month
 *   Goals     — average goal achievement % (neutral 10 pts when no goals set)
 *
 * Each pillar maps a real metric to a 0–20 score.
 * The architecture is intentionally modular: replace any pillar independently
 * as future modules (Finance, Marketing, Membership) become available.
 */
class BusinessHealthScoreService
{
    public function __construct(
        private readonly AnalyticsRepository $analytics,
        private readonly GrowthMetricsRepository $growthRepo,
    ) {}

    public function getScore(int $merchantId): array
    {
        return Cache::remember(
            "growth.health.{$merchantId}",
            now()->addMinutes(5),
            fn() => $this->compute($merchantId)
        );
    }

    private function compute(int $merchantId): array
    {
        $pillars = [
            'revenue'    => $this->revenueScore($merchantId),
            'customer'   => $this->customerScore($merchantId),
            'operations' => $this->operationsScore($merchantId),
            'growth'     => $this->growthScore($merchantId),
            'goals'      => $this->goalsScore($merchantId),
        ];

        $total = array_sum(array_column($pillars, 'score'));
        $grade = $this->scoreToGrade($total);

        return [
            'score'      => $total,
            'grade'      => $grade,
            'grade_label'=> $this->gradeLabel($grade),
            'pillars'    => $pillars,
        ];
    }

    // ── Pillar: Revenue (20 pts) ──────────────────────────────────────────

    private function revenueScore(int $merchantId): array
    {
        $today   = Carbon::now();
        $dayNum  = (int) $today->format('j');
        $curFrom = $today->copy()->startOfMonth()->startOfDay();
        $curTo   = $today->copy()->endOfDay();
        $prevFrom = $today->copy()->subMonth()->startOfMonth()->startOfDay();
        $prevTo   = $prevFrom->copy()->addDays($dayNum - 1)->endOfDay();

        $current = $this->growthRepo->revenueInPeriod($curFrom, $curTo, $merchantId);
        $prior   = $this->growthRepo->revenueInPeriod($prevFrom, $prevTo, $merchantId);

        if ($prior == 0 && $current == 0) {
            return $this->pillar('revenue', 0, 'No revenue data', null);
        }

        $growthPct = $prior > 0
            ? round(($current - $prior) / $prior * 100, 1)
            : 100.0;

        // Map growth% to 0-20: ≥20% growth = 20, 0% = 10, ≥-20% decline = 0
        $score = match (true) {
            $growthPct >= 20  => 20,
            $growthPct >= 10  => 17,
            $growthPct >= 5   => 15,
            $growthPct >= 0   => 10,
            $growthPct >= -10 => 6,
            $growthPct >= -20 => 3,
            default           => 0,
        };

        return $this->pillar('revenue', $score, "{$growthPct}% vs prior month", $growthPct);
    }

    // ── Pillar: Customer (20 pts) ─────────────────────────────────────────

    private function customerScore(int $merchantId): array
    {
        $segments = $this->analytics->customerSegmentCounts($merchantId);
        $total    = $segments['total'];

        if ($total === 0) {
            return $this->pillar('customer', 0, 'No customer data', null);
        }

        $healthy = $segments['healthy'] + $segments['active'];
        $ratio   = round($healthy / $total * 100, 1);

        $score = match (true) {
            $ratio >= 80 => 20,
            $ratio >= 65 => 17,
            $ratio >= 50 => 14,
            $ratio >= 35 => 10,
            $ratio >= 20 => 6,
            default      => 3,
        };

        return $this->pillar('customer', $score, "{$ratio}% healthy/active customers", $ratio);
    }

    // ── Pillar: Operations (20 pts) ───────────────────────────────────────

    private function operationsScore(int $merchantId): array
    {
        $from = Carbon::now()->subDays(30)->startOfDay();
        $to   = Carbon::now()->endOfDay();

        $period     = $this->analytics->deliveredRevenueForPeriod($merchantId, $from, $to);
        $delivered  = $period['count'];
        $allTerminal = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['delivered', 'failed', 'cancelled'])
            ->whereBetween('order_created_at', [$from, $to])
            ->count();

        if ($allTerminal === 0) {
            return $this->pillar('operations', 10, 'No completed orders (last 30 days)', null);
        }

        $rate = round($delivered / $allTerminal * 100, 1);

        $score = match (true) {
            $rate >= 95 => 20,
            $rate >= 90 => 18,
            $rate >= 85 => 16,
            $rate >= 80 => 14,
            $rate >= 70 => 10,
            $rate >= 60 => 6,
            default     => 2,
        };

        return $this->pillar('operations', $score, "{$rate}% delivery success (30 days)", $rate);
    }

    // ── Pillar: Growth (20 pts) ───────────────────────────────────────────

    private function growthScore(int $merchantId): array
    {
        $today   = Carbon::now();
        $dayNum  = (int) $today->format('j');
        $curFrom = $today->copy()->startOfMonth()->startOfDay();
        $curTo   = $today->copy()->endOfDay();
        $prevFrom = $today->copy()->subMonth()->startOfMonth()->startOfDay();
        $prevTo   = $prevFrom->copy()->addDays($dayNum - 1)->endOfDay();

        $current = $this->growthRepo->newCustomersInPeriod($curFrom, $curTo, $merchantId);
        $prior   = $this->growthRepo->newCustomersInPeriod($prevFrom, $prevTo, $merchantId);

        if ($prior == 0 && $current == 0) {
            return $this->pillar('growth', 0, 'No new customer data', null);
        }

        $growthPct = $prior > 0
            ? round(($current - $prior) / $prior * 100, 1)
            : ($current > 0 ? 100.0 : 0.0);

        $score = match (true) {
            $growthPct >= 20  => 20,
            $growthPct >= 10  => 17,
            $growthPct >= 5   => 15,
            $growthPct >= 0   => 10,
            $growthPct >= -10 => 6,
            $growthPct >= -20 => 3,
            default           => 0,
        };

        return $this->pillar('growth', $score, "{$growthPct}% new customer growth", $growthPct);
    }

    // ── Pillar: Goals (20 pts) ────────────────────────────────────────────

    private function goalsScore(int $merchantId): array
    {
        $today = now()->toDateString();

        $goals = DB::table('merchant_goals')
            ->where('merchant_id', $merchantId)
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->get();

        if ($goals->isEmpty()) {
            // No goals = neutral 10 pts; encourages goal-setting without penalizing
            return $this->pillar('goals', 10, 'No active goals set (neutral)', null);
        }

        // Average goal completion % maps to 0–20
        $avgPct = $goals->avg('achievement_pct') ?? 0.0;

        $score = match (true) {
            $avgPct >= 100 => 20,
            $avgPct >= 85  => 18,
            $avgPct >= 70  => 15,
            $avgPct >= 50  => 12,
            $avgPct >= 30  => 8,
            $avgPct >= 10  => 4,
            default        => 0,
        };

        return $this->pillar('goals', $score, round($avgPct, 0) . '% avg goal achievement', $avgPct);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function pillar(string $name, int $score, string $description, ?float $value): array
    {
        return [
            'name'        => $name,
            'score'       => $score,
            'max'         => 20,
            'description' => $description,
            'value'       => $value,
        ];
    }

    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default      => 'F',
        };
    }

    private function gradeLabel(string $grade): string
    {
        return match ($grade) {
            'A' => 'Excellent',
            'B' => 'Good',
            'C' => 'Fair',
            'D' => 'Needs Attention',
            default => 'Critical',
        };
    }
}
