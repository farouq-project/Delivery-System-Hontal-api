<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MerchantGoal;
use App\Services\FeatureManager;
use App\Services\Growth\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function __construct(
        private readonly GoalService $service,
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

        $user = $request->user();
        $merchantId = in_array($user->role, ['super_admin', 'developer']) && $request->has('merchant_id')
            ? (int) $request->query('merchant_id')
            : (int) $user->merchant_id;

        return response()->json([
            'data' => $this->service->getProgress($merchantId),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $data = $request->validate([
            'metric'       => 'required|in:revenue,orders,customers,success_rate,new_customers',
            'target_value' => 'required|numeric|min:0',
            'period_type'  => 'required|in:daily,weekly,monthly,quarterly,yearly',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'notes'        => 'nullable|string|max:500',
        ]);

        $goal = $this->service->create((int) $request->user()->merchant_id, $data);

        return response()->json(['data' => $goal, 'message' => 'Goal created.'], 201);
    }

    public function update(Request $request, MerchantGoal $merchantGoal): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $data = $request->validate([
            'metric'       => 'sometimes|in:revenue,orders,customers,success_rate,new_customers',
            'target_value' => 'sometimes|numeric|min:0',
            'period_type'  => 'sometimes|in:daily,weekly,monthly,quarterly,yearly',
            'period_start' => 'sometimes|date',
            'period_end'   => 'sometimes|date',
            'notes'        => 'nullable|string|max:500',
        ]);

        $updated = $this->service->update($merchantGoal, $data);

        return response()->json(['data' => $updated, 'message' => 'Goal updated.']);
    }

    public function destroy(Request $request, MerchantGoal $merchantGoal): JsonResponse
    {
        if ($err = $this->guard($request)) return $err;

        $this->service->delete($merchantGoal);

        return response()->json(['message' => 'Goal deleted.']);
    }
}
