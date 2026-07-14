<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ExecutiveDashboardService;
use App\Services\FeatureManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutiveDashboardController extends Controller
{
    private const ALLOWED_ROLES = ['merchant_owner', 'developer', 'super_admin'];

    public function __construct(
        private readonly ExecutiveDashboardService $service,
        private readonly FeatureManager $features,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, self::ALLOWED_ROLES)) {
            return response()->json(['message' => 'Executive Dashboard is restricted to merchant owners.'], 403);
        }

        $merchantId = $user->merchant_id;

        if (!$merchantId) {
            return response()->json(['message' => 'No merchant context.'], 403);
        }

        // Feature flag gate — super_admin and developer always bypass
        if (!in_array($user->role, ['super_admin', 'developer'])) {
            if (!$this->features->isEnabled($merchantId, 'executive_dashboard')) {
                return response()->json(['message' => 'Executive Dashboard is not enabled for this merchant.'], 403);
            }
        }

        return response()->json([
            'data' => $this->service->getDashboard($merchantId),
        ]);
    }
}
