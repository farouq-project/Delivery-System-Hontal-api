<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantSubscription;
use App\Models\PlatformPlan;
use App\Services\MerchantActivityService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $query = MerchantSubscription::query()
            ->with([
                'merchant:id,company_name,email',
                'plan:id,name,slug,monthly_price',
            ])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->whereHas('merchant', fn($q) =>
                $q->where('company_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
            ))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function show(MerchantSubscription $subscription)
    {
        return response()->json([
            'data' => $subscription->load(['merchant', 'plan']),
        ]);
    }

    public function changePlan(Request $request, MerchantSubscription $subscription)
    {
        $data = $request->validate(['plan_id' => 'required|exists:platform_plans,id']);

        $newPlan    = PlatformPlan::findOrFail($data['plan_id']);
        $oldPlan    = $subscription->plan;
        $changeType = ($newPlan->monthly_price >= ($oldPlan?->monthly_price ?? 0)) ? 'upgrade' : 'downgrade';

        $subscription->update(['plan_id' => $newPlan->id]);

        MerchantActivityService::log(
            $subscription->merchant_id,
            "plan_{$changeType}",
            "Plan changed from {$oldPlan?->name} to {$newPlan->name}",
            ['old_plan' => $oldPlan?->name, 'new_plan' => $newPlan->name, 'change_type' => $changeType],
            $request->user()->id
        );

        return response()->json([
            'message' => ucfirst($changeType) . ' successful.',
            'data'    => $subscription->fresh()->load(['merchant:id,company_name', 'plan']),
        ]);
    }

    public function pause(Request $request, MerchantSubscription $subscription)
    {
        if ($subscription->status !== 'active') {
            return response()->json(['message' => 'Only active subscriptions can be paused.'], 422);
        }

        $subscription->update(['status' => 'paused', 'paused_at' => now()]);

        MerchantActivityService::log(
            $subscription->merchant_id,
            'subscription_paused',
            'Subscription paused by admin',
            [],
            $request->user()->id
        );

        return response()->json(['message' => 'Subscription paused.', 'data' => $subscription->fresh()]);
    }

    public function resume(Request $request, MerchantSubscription $subscription)
    {
        if ($subscription->status !== 'paused') {
            return response()->json(['message' => 'Only paused subscriptions can be resumed.'], 422);
        }

        $subscription->update(['status' => 'active', 'resumed_at' => now()]);

        MerchantActivityService::log(
            $subscription->merchant_id,
            'subscription_resumed',
            'Subscription resumed by admin',
            [],
            $request->user()->id
        );

        return response()->json(['message' => 'Subscription resumed.', 'data' => $subscription->fresh()]);
    }

    public function extendTrial(Request $request, MerchantSubscription $subscription)
    {
        $data = $request->validate(['days' => 'required|integer|min:1|max:365']);

        if ($subscription->status !== 'trial') {
            return response()->json(['message' => 'Only trial subscriptions can be extended.'], 422);
        }

        $base     = $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : now();
        $newEnds  = $base->copy()->addDays($data['days']);

        $subscription->update(['trial_ends_at' => $newEnds]);

        MerchantActivityService::log(
            $subscription->merchant_id,
            'trial_extended',
            "Trial extended by {$data['days']} days (new end: {$newEnds->toDateString()})",
            ['days' => $data['days'], 'new_trial_ends_at' => $newEnds->toDateString()],
            $request->user()->id
        );

        return response()->json([
            'message' => "Trial extended by {$data['days']} days.",
            'data'    => $subscription->fresh(),
        ]);
    }

    public function activate(Request $request, MerchantSubscription $subscription)
    {
        $subscription->update([
            'status'     => 'active',
            'started_at' => $subscription->started_at ?? now(),
            'expires_at' => now()->addMonth(),
        ]);

        MerchantActivityService::log(
            $subscription->merchant_id,
            'subscription_activated',
            'Subscription manually activated by admin',
            [],
            $request->user()->id
        );

        return response()->json(['message' => 'Subscription activated.', 'data' => $subscription->fresh()]);
    }

    public function expire(Request $request, MerchantSubscription $subscription)
    {
        $subscription->update(['status' => 'expired', 'expires_at' => now()]);

        MerchantActivityService::log(
            $subscription->merchant_id,
            'subscription_expired',
            'Subscription manually expired by admin',
            [],
            $request->user()->id
        );

        return response()->json(['message' => 'Subscription expired.', 'data' => $subscription->fresh()]);
    }

    public function cancel(Request $request, MerchantSubscription $subscription)
    {
        $subscription->update(['status' => 'cancelled']);

        MerchantActivityService::log(
            $subscription->merchant_id,
            'subscription_cancelled',
            'Subscription cancelled by admin',
            [],
            $request->user()->id
        );

        return response()->json(['message' => 'Subscription cancelled.', 'data' => $subscription->fresh()]);
    }
}
