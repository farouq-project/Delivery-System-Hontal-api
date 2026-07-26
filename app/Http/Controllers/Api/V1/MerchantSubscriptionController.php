<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MerchantSubscription;
use Illuminate\Http\Request;

class MerchantSubscriptionController extends Controller
{
    public function show(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $subscription = MerchantSubscription::with(['plan'])
            ->where('merchant_id', $merchantId)
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['data' => null]);
        }

        $plan = $subscription->plan;

        return response()->json([
            'data' => [
                'status'             => $subscription->status,
                'plan_name'          => $plan?->name,
                'plan_slug'          => $plan?->slug,
                'delivery_limit'     => $plan?->delivery_limit,
                'credits_used'       => $subscription->credits_used,
                'extra_credits'      => $subscription->extra_credits,
                'credits_available'  => $subscription->creditsAvailable(),
                'credits_reset_at'   => $subscription->credits_reset_at,
                'trial_ends_at'      => $subscription->trial_ends_at,
                'expires_at'         => $subscription->expires_at,
                'is_trial'           => $subscription->isTrialing(),
                'days_in_trial'      => $subscription->daysRemainingInTrial(),
                'included_routing_mode' => $plan?->included_routing_mode,
            ],
        ]);
    }
}
