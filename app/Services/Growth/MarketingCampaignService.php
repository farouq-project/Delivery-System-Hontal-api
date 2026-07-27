<?php

namespace App\Services\Growth;

use App\Models\MarketingCampaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketingCampaignService
{
    public function list(int $merchantId): Collection
    {
        return MarketingCampaign::where('merchant_id', $merchantId)
            ->orderByDesc('start_date')
            ->get()
            ->each(fn($c) => $c->appendKpis());
    }

    public function create(int $merchantId, array $data): MarketingCampaign
    {
        $campaign = MarketingCampaign::create(array_merge($data, ['merchant_id' => $merchantId]));
        return $campaign->appendKpis();
    }

    public function update(MarketingCampaign $campaign, array $data): MarketingCampaign
    {
        $campaign->update($data);
        return $campaign->fresh()->appendKpis();
    }

    public function delete(MarketingCampaign $campaign): void
    {
        $campaign->delete();
    }

    /**
     * Aggregated marketing metrics across all campaigns for a merchant.
     */
    public function getAggregateMetrics(int $merchantId): array
    {
        $row = DB::table('marketing_campaigns')
            ->where('merchant_id', $merchantId)
            ->selectRaw('
                COUNT(*) as total_campaigns,
                COALESCE(SUM(budget), 0) as total_budget,
                COALESCE(SUM(spend), 0) as total_spend,
                COALESCE(SUM(reach), 0) as total_reach,
                COALESCE(SUM(clicks), 0) as total_clicks,
                COALESCE(SUM(wa_conversations), 0) as total_conversations,
                COALESCE(SUM(leads), 0) as total_leads,
                COALESCE(SUM(orders), 0) as total_orders,
                COALESCE(SUM(customers_acquired), 0) as total_customers_acquired,
                COALESCE(SUM(attributed_revenue), 0) as total_attributed_revenue
            ')
            ->first();

        if (!$row || $row->total_campaigns == 0) {
            return [
                'total_campaigns'      => 0,
                'total_budget'         => 0.0,
                'total_spend'          => 0.0,
                'total_reach'          => 0,
                'total_clicks'         => 0,
                'total_conversations'  => 0,
                'total_leads'          => 0,
                'total_orders'         => 0,
                'total_customers_acquired' => 0,
                'total_attributed_revenue' => 0.0,
                'overall_cac'          => null,
                'overall_roas'         => null,
                'overall_conversion_rate' => null,
            ];
        }

        $spend    = (float) $row->total_spend;
        $leads    = (int)   $row->total_leads;
        $orders   = (int)   $row->total_orders;
        $acquired = (int)   $row->total_customers_acquired;
        $revenue  = (float) $row->total_attributed_revenue;

        return [
            'total_campaigns'      => (int) $row->total_campaigns,
            'total_budget'         => (float) $row->total_budget,
            'total_spend'          => $spend,
            'total_reach'          => (int) $row->total_reach,
            'total_clicks'         => (int) $row->total_clicks,
            'total_conversations'  => (int) $row->total_conversations,
            'total_leads'          => $leads,
            'total_orders'         => $orders,
            'total_customers_acquired' => $acquired,
            'total_attributed_revenue' => $revenue,
            'overall_cac'          => $acquired > 0 ? round($spend / $acquired, 0) : null,
            'overall_roas'         => $spend > 0 ? round($revenue / $spend, 2) : null,
            'overall_conversion_rate' => $leads > 0 ? round($orders / $leads * 100, 1) : null,
        ];
    }

    /**
     * Revenue contribution by lead source.
     */
    public function revenueByLeadSource(int $merchantId): Collection
    {
        return DB::table('marketing_campaigns')
            ->where('merchant_id', $merchantId)
            ->select([
                'lead_source',
                DB::raw('COUNT(*) as campaigns'),
                DB::raw('COALESCE(SUM(spend), 0) as total_spend'),
                DB::raw('COALESCE(SUM(leads), 0) as total_leads'),
                DB::raw('COALESCE(SUM(orders), 0) as total_orders'),
                DB::raw('COALESCE(SUM(attributed_revenue), 0) as total_revenue'),
            ])
            ->groupBy('lead_source')
            ->orderByDesc('total_revenue')
            ->get();
    }
}
