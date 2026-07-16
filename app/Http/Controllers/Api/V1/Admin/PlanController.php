<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /**
     * GET /api/v1/admin/plans
     */
    public function index()
    {
        $plans = PlatformPlan::orderBy('display_order')->get()->map(fn($p) => [
            'id'             => $p->id,
            'name'           => $p->name,
            'slug'           => $p->slug,
            'description'    => $p->description,
            'monthly_price'  => $p->monthly_price,
            'delivery_limit' => $p->delivery_limit,
            'branch_limit'   => $p->branch_limit,
            'driver_limit'   => $p->driver_limit,
            'features'       => $p->features ?? [],
            'is_active'      => $p->is_active,
            'subscriber_count' => $p->subscriptions()->count(),
        ]);

        return response()->json(['data' => $plans]);
    }

    /**
     * GET /api/v1/admin/plans/{plan}
     */
    public function show(PlatformPlan $plan)
    {
        return response()->json(['data' => $plan->loadCount('subscriptions')]);
    }

    /**
     * PATCH /api/v1/admin/plans/{plan}/toggle
     * Toggle plan active state.
     */
    public function toggle(PlatformPlan $plan)
    {
        $plan->update(['is_active' => !$plan->is_active]);
        return response()->json(['data' => $plan, 'message' => 'Plan updated.']);
    }
}
