<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantSubscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /**
     * GET /api/v1/admin/subscriptions
     */
    public function index(Request $request)
    {
        $query = MerchantSubscription::query()
            ->with([
                'merchant:id,company_name,email',
                'plan:id,name,slug,monthly_price',
            ])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->whereHas('merchant', fn($q) => $q->where('company_name', 'like', "%{$s}%")))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    /**
     * GET /api/v1/admin/subscriptions/{subscription}
     */
    public function show(MerchantSubscription $subscription)
    {
        return response()->json([
            'data' => $subscription->load(['merchant', 'plan']),
        ]);
    }
}
