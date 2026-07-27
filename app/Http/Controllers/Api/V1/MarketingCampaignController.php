<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Services\FeatureManager;
use App\Services\Growth\MarketingCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingCampaignController extends Controller
{
    public function __construct(
        private readonly MarketingCampaignService $service,
        private readonly FeatureManager $features,
    ) {}

    private function guard(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->role, ['merchant_owner', 'developer', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (!in_array($user->role, ['super_admin', 'developer'])) {
            if (!$this->features->isEnabled($user->merchant_id, 'business_intelligence')) {
                return response()->json(['message' => 'Business Growth is not enabled.'], 403);
            }
        }
        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $merchantId = (int) $request->user()->merchant_id;

        return response()->json([
            'data' => [
                'campaigns'  => $this->service->list($merchantId)->values(),
                'aggregate'  => $this->service->getAggregateMetrics($merchantId),
                'by_source'  => $this->service->revenueByLeadSource($merchantId)->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'platform'          => 'required|in:whatsapp,instagram,facebook,tiktok,google,offline,other',
            'lead_source'       => 'required|in:google,meta,instagram,whatsapp,tiktok,referral,marketplace,offline,walk_in,other',
            'campaign_type'     => 'required|in:broadcast,promo,referral,seasonal,retention,acquisition',
            'start_date'        => 'required|date',
            'end_date'          => 'nullable|date|after_or_equal:start_date',
            'budget'            => 'nullable|numeric|min:0',
            'spend'             => 'nullable|numeric|min:0',
            'reach'             => 'nullable|integer|min:0',
            'clicks'            => 'nullable|integer|min:0',
            'wa_conversations'  => 'nullable|integer|min:0',
            'leads'             => 'nullable|integer|min:0',
            'orders'            => 'nullable|integer|min:0',
            'customers_acquired'=> 'nullable|integer|min:0',
            'attributed_revenue'=> 'nullable|numeric|min:0',
            'notes'             => 'nullable|string|max:5000',
        ]);

        $campaign = $this->service->create((int) $request->user()->merchant_id, $data);

        return response()->json(['data' => $campaign, 'message' => 'Campaign created.'], 201);
    }

    public function show(Request $request, MarketingCampaign $marketingCampaign): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        return response()->json(['data' => $marketingCampaign->appendKpis()]);
    }

    public function update(Request $request, MarketingCampaign $marketingCampaign): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $data = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'platform'          => 'sometimes|in:whatsapp,instagram,facebook,tiktok,google,offline,other',
            'lead_source'       => 'sometimes|in:google,meta,instagram,whatsapp,tiktok,referral,marketplace,offline,walk_in,other',
            'campaign_type'     => 'sometimes|in:broadcast,promo,referral,seasonal,retention,acquisition',
            'start_date'        => 'sometimes|date',
            'end_date'          => 'nullable|date',
            'budget'            => 'nullable|numeric|min:0',
            'spend'             => 'nullable|numeric|min:0',
            'reach'             => 'nullable|integer|min:0',
            'clicks'            => 'nullable|integer|min:0',
            'wa_conversations'  => 'nullable|integer|min:0',
            'leads'             => 'nullable|integer|min:0',
            'orders'            => 'nullable|integer|min:0',
            'customers_acquired'=> 'nullable|integer|min:0',
            'attributed_revenue'=> 'nullable|numeric|min:0',
            'notes'             => 'nullable|string|max:5000',
        ]);

        $updated = $this->service->update($marketingCampaign, $data);

        return response()->json(['data' => $updated, 'message' => 'Campaign updated.']);
    }

    public function destroy(Request $request, MarketingCampaign $marketingCampaign): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $this->service->delete($marketingCampaign);

        return response()->json(['message' => 'Campaign deleted.']);
    }
}
