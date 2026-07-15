<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\BusinessMetricsService;
use App\Services\FeatureManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessIntelligenceController extends Controller
{
    private const ALLOWED_ROLES = ['merchant_owner', 'developer', 'super_admin'];
    private const FEATURE       = 'business_intelligence';

    public function __construct(
        private readonly BusinessMetricsService $metrics,
        private readonly FeatureManager $features,
    ) {}

    // ── Auth / Feature Gate ───────────────────────────────────────────────────

    private function guard(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, self::ALLOWED_ROLES)) {
            return response()->json(['message' => 'Business Intelligence is restricted to merchant owners.'], 403);
        }

        if (!$user->merchant_id) {
            return response()->json(['message' => 'No merchant context.'], 403);
        }

        // super_admin and developer always bypass the feature flag
        if (!in_array($user->role, ['super_admin', 'developer'])) {
            if (!$this->features->isEnabled($user->merchant_id, self::FEATURE)) {
                return response()->json(['message' => 'Business Intelligence is not enabled for this merchant.'], 403);
            }
        }

        return null;
    }

    private function timezone(int $merchantId): string
    {
        $merchant = Merchant::find($merchantId);
        return $merchant?->timezone ?? config('app.timezone', 'Asia/Jakarta');
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function overview(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;
        $mid = $request->user()->merchant_id;

        return response()->json([
            'data' => $this->metrics->getOverview($mid, $this->timezone($mid)),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;
        $mid = $request->user()->merchant_id;

        return response()->json([
            'data' => $this->metrics->getCustomerInsights($mid, $this->timezone($mid)),
        ]);
    }

    public function operations(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;
        $mid = $request->user()->merchant_id;

        return response()->json([
            'data' => $this->metrics->getOperationsInsights($mid, $this->timezone($mid)),
        ]);
    }

    public function drivers(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;
        $mid = $request->user()->merchant_id;

        return response()->json([
            'data' => $this->metrics->getDriverInsights($mid),
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;
        $mid = $request->user()->merchant_id;

        return response()->json([
            'data' => $this->metrics->getBranchInsights($mid),
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;
        $mid = $request->user()->merchant_id;

        return response()->json([
            'data' => $this->metrics->getProductInsights($mid),
        ]);
    }

    public function areas(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;
        $mid = $request->user()->merchant_id;

        return response()->json([
            'data' => $this->metrics->getAreaInsights($mid),
        ]);
    }

    public function attention(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;
        $mid = $request->user()->merchant_id;

        return response()->json([
            'data' => $this->metrics->getRequiresAttention($mid),
        ]);
    }
}
