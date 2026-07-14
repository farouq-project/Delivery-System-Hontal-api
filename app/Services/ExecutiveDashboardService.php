<?php

namespace App\Services;

use App\Models\Merchant;

/**
 * Assembles the Executive Dashboard response.
 *
 * All KPI calculations are delegated to BusinessMetricsService.
 * This class is responsible only for resolving merchant timezone
 * and combining the six dashboard sections into one response.
 */
class ExecutiveDashboardService
{
    public function __construct(
        private readonly BusinessMetricsService $metrics,
    ) {}

    public function getDashboard(int $merchantId): array
    {
        $merchant = Merchant::find($merchantId);
        $timezone = $merchant?->timezone ?? config('app.timezone', 'Asia/Jakarta');

        return [
            'operations_today'    => $this->metrics->getOperationsToday($merchantId, $timezone),
            'business_this_month' => $this->metrics->getBusinessThisMonth($merchantId, $timezone),
            'customer_health'     => $this->metrics->getCustomerHealth($merchantId, $timezone),
            'cluster_summary'     => $this->metrics->getClusterSummary($merchantId),
            'recent_activity'     => $this->metrics->getRecentActivity($merchantId),
            'requires_attention'  => $this->metrics->getRequiresAttention($merchantId),
        ];
    }
}
