<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Services\MerchantProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MerchantController extends Controller
{
    public function __construct(
        private readonly MerchantProvisioningService $provisioningService
    ) {}

    /**
     * GET /api/v1/admin/merchants
     * Paginated, server-side searchable merchant directory.
     */
    public function index(Request $request)
    {
        $query = Merchant::query()
            ->select('merchants.*')
            ->with([
                'subscription.plan:id,name,slug,monthly_price',
            ])
            ->withCount('branches')
            // Eager-load the single merchant_owner user
            ->with(['users' => fn($q) => $q->where('role', 'merchant_owner')->select('id', 'merchant_id', 'name', 'email', 'last_login_at')->limit(1)])
            // Monthly delivery count (this month, delivered)
            ->withCount([
                'orders as monthly_deliveries' => fn($q) => $q
                    ->where('status', 'delivered')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year),
            ])
            // Monthly revenue (this month, delivered)
            ->withSum(['orders as monthly_revenue' => fn($q) => $q
                ->where('status', 'delivered')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year),
            ], 'order_value')
            ->when($request->search, fn($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('merchants.company_name', 'like', "%{$s}%")
                  ->orWhere('merchants.email', 'like', "%{$s}%");
            }))
            ->orderBy('merchants.created_at', 'desc');

        $merchants = $query->paginate($request->per_page ?? 20);

        // Shape the response for the directory view
        $merchants->through(fn($m) => [
            'id'                 => $m->id,
            'company_name'       => $m->company_name,
            'email'              => $m->email,
            'created_at'         => $m->created_at,
            'owner'              => $m->users->first() ? [
                'name'          => $m->users->first()->name,
                'email'         => $m->users->first()->email,
                'last_login_at' => $m->users->first()->last_login_at,
            ] : null,
            'subscription'       => $m->subscription ? [
                'status'        => $m->subscription->status,
                'plan_name'     => $m->subscription->plan?->name,
                'trial_ends_at' => $m->subscription->trial_ends_at?->toDateString(),
                'expires_at'    => $m->subscription->expires_at?->toDateString(),
            ] : null,
            'branches_count'     => $m->branches_count,
            'monthly_deliveries' => $m->monthly_deliveries ?? 0,
            'monthly_revenue'    => $m->monthly_revenue ?? 0,
        ]);

        return response()->json($merchants);
    }

    /**
     * GET /api/v1/admin/merchants/{merchant}
     * Full merchant detail for the admin view.
     */
    public function show(Merchant $merchant)
    {
        $merchant->load([
            'subscription.plan',
            'users:id,merchant_id,name,email,role,is_active,last_login_at',
            'branches:id,merchant_id,name,address,is_active',
            'settings',
            'features:id,merchant_id,feature,is_enabled',
        ]);

        $deliverySummary = DB::table('delivery_orders')
            ->where('merchant_id', $merchant->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $monthlyRevenue = DB::table('delivery_orders')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'delivered')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('order_value');

        return response()->json([
            'data' => [
                'merchant'        => $merchant,
                'delivery_summary'=> $deliverySummary,
                'monthly_revenue' => $monthlyRevenue,
            ],
        ]);
    }

    /**
     * PATCH /api/v1/admin/merchants/{merchant}/status
     * Module 8 — Trial Management: approve_trial, end_trial, activate, suspend, deactivate.
     */
    public function updateStatus(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'status' => ['required', 'in:' . implode(',', MerchantSubscription::STATUSES)],
        ]);

        try {
            $subscription = $this->provisioningService->updateSubscriptionStatus($merchant, $data['status']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Merchant status updated to {$data['status']}.",
            'data'    => $subscription->load('plan:id,name,slug'),
        ]);
    }

    /**
     * GET /api/v1/admin/merchants/{merchant}/users
     */
    public function users(Merchant $merchant)
    {
        $users = $merchant->users()
            ->select('id', 'ulid', 'name', 'email', 'role', 'is_active', 'last_login_at')
            ->orderBy('role')
            ->orderBy('name')
            ->paginate(25);

        return response()->json($users);
    }

    /**
     * GET /api/v1/admin/merchants/{merchant}/delivery-summary
     */
    public function deliverySummary(Merchant $merchant)
    {
        $byStatus = DB::table('delivery_orders')
            ->where('merchant_id', $merchant->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        $byMonth = DB::table('delivery_orders')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'delivered')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as deliveries, SUM(order_value) as revenue")
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->orderByDesc('month')
            ->limit(12)
            ->get();

        return response()->json(['data' => compact('byStatus', 'byMonth')]);
    }
}
