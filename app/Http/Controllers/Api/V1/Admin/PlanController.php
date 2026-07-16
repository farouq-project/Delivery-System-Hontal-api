<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $query = PlatformPlan::query()
            ->when($request->boolean('archived'), fn($q) => $q->onlyTrashed())
            ->orderBy('display_order');

        $plans = $query->get()->map(fn($p) => [
            'id'               => $p->id,
            'name'             => $p->name,
            'slug'             => $p->slug,
            'description'      => $p->description,
            'monthly_price'    => $p->monthly_price,
            'trial_days'       => $p->trial_days ?? 14,
            'delivery_limit'   => $p->delivery_limit,
            'branch_limit'     => $p->branch_limit,
            'driver_limit'     => $p->driver_limit,
            'customer_limit'   => $p->customer_limit,
            'features'         => $p->features ?? [],
            'is_active'        => $p->is_active,
            'display_order'    => $p->display_order,
            'deleted_at'       => $p->deleted_at,
            'subscriber_count' => $p->subscriptions()->whereNotIn('status', ['cancelled', 'expired'])->count(),
        ]);

        return response()->json(['data' => $plans]);
    }

    public function show(PlatformPlan $plan)
    {
        return response()->json(['data' => $plan->loadCount('subscriptions')]);
    }

    public function store(Request $request)
    {
        $featureKeys = implode(',', array_keys(PlatformPlan::AVAILABLE_FEATURES));

        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'slug'           => 'required|string|max:100|unique:platform_plans,slug',
            'description'    => 'nullable|string|max:500',
            'monthly_price'  => 'required|integer|min:0',
            'trial_days'     => 'integer|min:0|max:365',
            'delivery_limit' => 'nullable|integer|min:1',
            'driver_limit'   => 'nullable|integer|min:1',
            'branch_limit'   => 'nullable|integer|min:1',
            'customer_limit' => 'nullable|integer|min:1',
            'features'       => 'nullable|array',
            'features.*'     => "string|in:{$featureKeys}",
            'is_active'      => 'boolean',
            'display_order'  => 'integer|min:0',
        ]);

        $plan = PlatformPlan::create($data);
        return response()->json(['data' => $plan, 'message' => 'Plan created.'], 201);
    }

    public function update(Request $request, PlatformPlan $plan)
    {
        $featureKeys = implode(',', array_keys(PlatformPlan::AVAILABLE_FEATURES));

        $data = $request->validate([
            'name'           => 'string|max:100',
            'slug'           => "string|max:100|unique:platform_plans,slug,{$plan->id}",
            'description'    => 'nullable|string|max:500',
            'monthly_price'  => 'integer|min:0',
            'trial_days'     => 'integer|min:0|max:365',
            'delivery_limit' => 'nullable|integer|min:1',
            'driver_limit'   => 'nullable|integer|min:1',
            'branch_limit'   => 'nullable|integer|min:1',
            'customer_limit' => 'nullable|integer|min:1',
            'features'       => 'nullable|array',
            'features.*'     => "string|in:{$featureKeys}",
            'is_active'      => 'boolean',
            'display_order'  => 'integer|min:0',
        ]);

        $plan->update($data);
        return response()->json(['data' => $plan, 'message' => 'Plan updated.']);
    }

    public function toggle(PlatformPlan $plan)
    {
        $plan->update(['is_active' => !$plan->is_active]);
        return response()->json(['data' => $plan, 'message' => 'Plan updated.']);
    }

    public function duplicate(PlatformPlan $plan)
    {
        $baseSlug = $plan->slug . '-copy';
        $newSlug  = $baseSlug;
        $counter  = 1;

        while (PlatformPlan::withTrashed()->where('slug', $newSlug)->exists()) {
            $counter++;
            $newSlug = $baseSlug . '-' . $counter;
        }

        $copy            = $plan->replicate(['deleted_at']);
        $copy->name      = $plan->name . ' (Copy)';
        $copy->slug      = $newSlug;
        $copy->is_active = false;
        $copy->save();

        return response()->json(['data' => $copy, 'message' => 'Plan duplicated.'], 201);
    }

    public function archive(PlatformPlan $plan)
    {
        $plan->delete();
        return response()->json(['message' => 'Plan archived.']);
    }

    public function restore(int $id)
    {
        $plan = PlatformPlan::withTrashed()->findOrFail($id);
        abort_unless($plan->trashed(), 422, 'Plan is not archived.');
        $plan->restore();
        return response()->json(['data' => $plan, 'message' => 'Plan restored.']);
    }

    public function destroy(PlatformPlan $plan)
    {
        $activeCount = $plan->subscriptions()
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->count();

        if ($activeCount > 0) {
            return response()->json([
                'message' => "Plan has {$activeCount} active subscriber(s). Archive it instead.",
            ], 422);
        }

        $plan->forceDelete();
        return response()->json(null, 204);
    }
}
