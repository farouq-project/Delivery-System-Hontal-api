<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\MerchantActivityService;
use App\Services\MerchantProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MerchantController extends Controller
{
    public function __construct(
        private readonly MerchantProvisioningService $provisioningService
    ) {}

    public function index(Request $request)
    {
        $query = Merchant::query()
            ->select('merchants.*')
            ->with(['subscription.plan:id,name,slug,monthly_price'])
            ->withCount('branches')
            ->with(['users' => fn($q) => $q->where('role', 'merchant_owner')
                ->select('id', 'merchant_id', 'name', 'email', 'last_login_at')
                ->limit(1)])
            ->withCount([
                'orders as monthly_deliveries' => fn($q) => $q
                    ->where('status', 'delivered')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year),
            ])
            ->withSum(['orders as monthly_revenue' => fn($q) => $q
                ->where('status', 'delivered')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year),
            ], 'order_value')
            ->when($request->search, fn($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('merchants.company_name', 'like', "%{$s}%")
                  ->orWhere('merchants.email', 'like', "%{$s}%")
                  ->orWhere('merchants.phone', 'like', "%{$s}%")
                  ->orWhere('merchants.id', is_numeric($s) ? (int) $s : -1)
                  ->orWhereHas('users', fn($u) => $u
                      ->where('role', 'merchant_owner')
                      ->where(fn($u) => $u
                          ->where('name', 'like', "%{$s}%")
                          ->orWhere('email', 'like', "%{$s}%")
                      )
                  );
            }))
            ->when($request->subscription_status, fn($q, $st) =>
                $q->whereHas('subscription', fn($q) => $q->where('status', $st))
            )
            ->when($request->plan, fn($q, $pl) =>
                $q->whereHas('subscription.plan', fn($q) => $q->where('slug', $pl))
            );

        $sort = $request->sort ?? 'created';
        match ($sort) {
            'name'       => $query->orderBy('merchants.company_name'),
            'revenue'    => $query->orderByDesc('monthly_revenue'),
            'deliveries' => $query->orderByDesc('monthly_deliveries'),
            default      => $query->orderByDesc('merchants.created_at'),
        };

        $merchants = $query->paginate($request->per_page ?? 20);

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
                'id'            => $m->subscription->id,
                'status'        => $m->subscription->status,
                'plan_name'     => $m->subscription->plan?->name,
                'plan_slug'     => $m->subscription->plan?->slug,
                'trial_ends_at' => $m->subscription->trial_ends_at?->toDateString(),
                'expires_at'    => $m->subscription->expires_at?->toDateString(),
            ] : null,
            'branches_count'     => $m->branches_count,
            'monthly_deliveries' => $m->monthly_deliveries ?? 0,
            'monthly_revenue'    => $m->monthly_revenue ?? 0,
        ]);

        return response()->json($merchants);
    }

    public function show(Merchant $merchant)
    {
        $merchant->load([
            'subscription.plan',
            'users:id,merchant_id,name,email,role,is_active,last_login_at',
            'branches:id,merchant_id,name,address,is_active',
            'settings',
            'features:id,merchant_id,feature,is_enabled',
            'billing',
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
                'merchant'         => $merchant,
                'delivery_summary' => $deliverySummary,
                'monthly_revenue'  => $monthlyRevenue,
            ],
        ]);
    }

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

    public function users(Merchant $merchant)
    {
        $users = $merchant->users()
            ->select('id', 'ulid', 'name', 'email', 'role', 'is_active', 'last_login_at')
            ->orderBy('role')
            ->orderBy('name')
            ->paginate(25);

        return response()->json($users);
    }

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

    public function features(Merchant $merchant)
    {
        $available = PlatformPlan::AVAILABLE_FEATURES;
        $enabled   = $merchant->features()->pluck('is_enabled', 'feature')->toArray();

        $features = collect($available)->map(fn($label, $key) => [
            'key'        => $key,
            'label'      => $label,
            'is_enabled' => (bool) ($enabled[$key] ?? false),
        ])->values();

        return response()->json(['data' => $features]);
    }

    public function updateFeature(Request $request, Merchant $merchant, string $featureKey)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);

        if (!array_key_exists($featureKey, PlatformPlan::AVAILABLE_FEATURES)) {
            return response()->json(['message' => 'Invalid feature key.'], 422);
        }

        $feature = $merchant->features()->firstOrCreate(
            ['feature' => $featureKey],
            ['is_enabled' => false]
        );
        $feature->update(['is_enabled' => $data['enabled']]);

        MerchantActivityService::log(
            $merchant->id,
            'feature_updated',
            sprintf(
                '%s feature "%s"',
                $data['enabled'] ? 'Enabled' : 'Disabled',
                PlatformPlan::AVAILABLE_FEATURES[$featureKey]
            ),
            ['feature' => $featureKey, 'enabled' => $data['enabled']],
            $request->user()->id
        );

        return response()->json(['data' => $feature, 'message' => 'Feature updated.']);
    }

    public function usage(Merchant $merchant)
    {
        $sub  = $merchant->subscription;
        $plan = $sub?->plan;

        $deliveries = DB::table('delivery_orders')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'delivered')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $drivers = DB::table('drivers')
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->count();

        $branches = DB::table('merchant_branches')
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->count();

        $customers = DB::table('customers')
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->count();

        return response()->json([
            'data' => [
                'plan'        => $plan ? ['name' => $plan->name, 'slug' => $plan->slug] : null,
                'subscription_status' => $sub?->status,
                'deliveries'  => ['used' => $deliveries,  'limit' => $plan?->delivery_limit],
                'drivers'     => ['used' => $drivers,     'limit' => $plan?->driver_limit],
                'branches'    => ['used' => $branches,    'limit' => $plan?->branch_limit],
                'customers'   => ['used' => $customers,   'limit' => $plan?->customer_limit],
            ],
        ]);
    }

    public function activity(Request $request, Merchant $merchant)
    {
        $log = $merchant->activityLog()
            ->with('actor:id,name,email')
            ->when($request->event_type, fn($q, $t) => $q->where('event_type', $t))
            ->paginate($request->per_page ?? 25);

        return response()->json($log);
    }

    public function resetUserPassword(Request $request, Merchant $merchant, User $user)
    {
        abort_unless($user->merchant_id === $merchant->id, 403, 'User does not belong to this merchant.');

        $newPassword = Str::password(10, symbols: false);
        $user->update(['password' => Hash::make($newPassword)]);

        MerchantActivityService::log(
            $merchant->id,
            'user_password_reset',
            "Password reset for user {$user->name} ({$user->email})",
            ['user_id' => $user->id],
            $request->user()->id
        );

        return response()->json(['message' => 'Password reset.', 'temp_password' => $newPassword]);
    }

    public function deactivateUser(Request $request, Merchant $merchant, User $user)
    {
        abort_unless($user->merchant_id === $merchant->id, 403, 'User does not belong to this merchant.');

        $user->update(['is_active' => false]);

        MerchantActivityService::log(
            $merchant->id,
            'user_deactivated',
            "User {$user->name} ({$user->email}) deactivated",
            ['user_id' => $user->id],
            $request->user()->id
        );

        return response()->json(['message' => 'User deactivated.']);
    }

    public function reactivateUser(Request $request, Merchant $merchant, User $user)
    {
        abort_unless($user->merchant_id === $merchant->id, 403, 'User does not belong to this merchant.');

        $user->update(['is_active' => true]);

        MerchantActivityService::log(
            $merchant->id,
            'user_reactivated',
            "User {$user->name} ({$user->email}) reactivated",
            ['user_id' => $user->id],
            $request->user()->id
        );

        return response()->json(['message' => 'User reactivated.']);
    }
}
