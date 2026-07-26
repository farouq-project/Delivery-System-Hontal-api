<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditPackOption;
use App\Models\MerchantCreditPurchase;
use App\Models\MerchantSubscription;
use App\Services\MerchantActivityService;
use Illuminate\Http\Request;

class CreditPackController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => CreditPackOption::active()->get(),
        ]);
    }

    public function grantCredits(Request $request, MerchantSubscription $subscription)
    {
        $data = $request->validate([
            'pack_slug' => 'required|string|exists:credit_pack_options,slug',
            'note'      => 'nullable|string|max:500',
        ]);

        $pack = CreditPackOption::where('slug', $data['pack_slug'])->where('is_active', true)->firstOrFail();

        $subscription->increment('extra_credits', $pack->credits);

        MerchantCreditPurchase::create([
            'merchant_id'          => $subscription->merchant_id,
            'subscription_id'      => $subscription->id,
            'credit_pack_option_id'=> $pack->id,
            'credits_granted'      => $pack->credits,
            'price_paid_idr'       => $pack->price_idr,
            'granted_by'           => $request->user()->id,
            'note'                 => $data['note'] ?? null,
        ]);

        MerchantActivityService::log(
            $subscription->merchant_id,
            'credits_granted',
            "Admin granted {$pack->credits} credits ({$pack->name})",
            ['pack' => $pack->slug, 'credits' => $pack->credits, 'price_idr' => $pack->price_idr],
            $request->user()->id
        );

        return response()->json([
            'message'           => "{$pack->credits} credits granted.",
            'credits_granted'   => $pack->credits,
            'credits_available' => $subscription->fresh()->creditsAvailable(),
        ]);
    }
}
