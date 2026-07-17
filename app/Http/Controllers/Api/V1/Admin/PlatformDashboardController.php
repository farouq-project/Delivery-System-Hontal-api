<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantActivityLog;
use App\Models\MerchantApplication;
use App\Models\MerchantSubscription;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformDashboardController extends Controller
{
    // ─── PART 1: Platform Dashboard ───────────────────────────────────────────

    public function dashboard(): JsonResponse
    {
        $now = now();

        // Merchant counts by subscription status
        $merchantCounts = DB::table('merchants')
            ->leftJoin(
                DB::raw('(SELECT merchant_id, status FROM merchant_subscriptions ms1 WHERE id = (SELECT MAX(id) FROM merchant_subscriptions ms2 WHERE ms2.merchant_id = ms1.merchant_id)) as latest_sub'),
                'merchants.id', '=', 'latest_sub.merchant_id'
            )
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN latest_sub.status = 'active' THEN 1 ELSE 0 END) as active_count")
            ->selectRaw("SUM(CASE WHEN latest_sub.status = 'trial' THEN 1 ELSE 0 END) as trial_count")
            ->selectRaw("SUM(CASE WHEN latest_sub.status IN ('expired','cancelled','suspended') THEN 1 ELSE 0 END) as inactive_count")
            ->whereNull('merchants.deleted_at')
            ->first();

        $paidMerchants = DB::table('merchants')
            ->join('merchant_subscriptions', function ($j) {
                $j->on('merchants.id', '=', 'merchant_subscriptions.merchant_id')
                  ->where('merchant_subscriptions.billing_cycle', '!=', '');
            })
            ->where('merchant_subscriptions.status', 'active')
            ->whereNull('merchants.deleted_at')
            ->count();

        $activeUsers = DB::table('users')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();

        $ordersToday = DB::table('delivery_orders')
            ->whereDate('created_at', $now->toDateString())
            ->whereNull('deleted_at')
            ->count();

        $deliveriesToday = DB::table('delivery_orders')
            ->where('status', 'delivered')
            ->whereDate('delivered_at', $now->toDateString())
            ->whereNull('deleted_at')
            ->count();

        $monthlyOrders = DB::table('delivery_orders')
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->whereNull('deleted_at')
            ->count();

        $googleApiStats = DB::table('google_api_usage_logs')
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->selectRaw('SUM(request_count) as requests, SUM(estimated_units) as units, SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits')
            ->first();

        $warningThreshold = (int) PlatformSetting::get('google_api_warning_threshold', 1000);
        $maintenanceMode  = (bool) PlatformSetting::get('maintenance_mode', false);

        $platformHealth = 'healthy';
        if ($maintenanceMode) {
            $platformHealth = 'maintenance';
        } elseif (($googleApiStats->units ?? 0) >= $warningThreshold) {
            $platformHealth = 'warning';
        }

        return response()->json([
            'data' => [
                'merchants' => [
                    'total'    => (int) ($merchantCounts->total ?? 0),
                    'active'   => (int) ($merchantCounts->active_count ?? 0),
                    'trial'    => (int) ($merchantCounts->trial_count ?? 0),
                    'paid'     => $paidMerchants,
                    'inactive' => (int) ($merchantCounts->inactive_count ?? 0),
                ],
                'users' => [
                    'active' => $activeUsers,
                ],
                'orders' => [
                    'today'       => $ordersToday,
                    'this_month'  => $monthlyOrders,
                ],
                'deliveries' => [
                    'today' => $deliveriesToday,
                ],
                'google_api' => [
                    'requests_this_month'      => (int) ($googleApiStats->requests ?? 0),
                    'estimated_units_this_month' => (int) ($googleApiStats->units ?? 0),
                    'cache_hits_this_month'    => (int) ($googleApiStats->cache_hits ?? 0),
                    'warning_threshold'        => $warningThreshold,
                ],
                'platform_health' => [
                    'status'           => $platformHealth,
                    'maintenance_mode' => $maintenanceMode,
                ],
            ],
        ]);
    }

    // ─── PART 2: Merchant Health ───────────────────────────────────────────────

    public function health(): JsonResponse
    {
        $merchants = Merchant::withTrashed()
            ->whereNull('merchants.deleted_at')
            ->with(['subscription.plan:id,name,slug'])
            ->select('merchants.id', 'merchants.company_name', 'merchants.email', 'merchants.created_at')
            ->get();

        $merchantIds = $merchants->pluck('id');

        // Last order date per merchant
        $lastOrders = DB::table('delivery_orders')
            ->whereIn('merchant_id', $merchantIds)
            ->whereNull('deleted_at')
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, MAX(created_at) as last_order_at, COUNT(*) as total_orders')
            ->get()
            ->keyBy('merchant_id');

        // Monthly orders
        $monthlyOrders = DB::table('delivery_orders')
            ->whereIn('merchant_id', $merchantIds)
            ->whereNull('deleted_at')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, COUNT(*) as count')
            ->get()
            ->keyBy('merchant_id');

        // Delivery success rate this month (terminal orders only)
        $successRates = DB::table('delivery_orders')
            ->whereIn('merchant_id', $merchantIds)
            ->whereNull('deleted_at')
            ->whereIn('status', ['delivered', 'failed'])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->groupBy('merchant_id')
            ->selectRaw("merchant_id, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered, COUNT(*) as terminal")
            ->get()
            ->keyBy('merchant_id');

        // Active drivers per merchant
        $activeDrivers = DB::table('drivers')
            ->whereIn('merchant_id', $merchantIds)
            ->where('is_active', true)
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, COUNT(*) as count')
            ->get()
            ->keyBy('merchant_id');

        // Active dispatchers per merchant
        $dispatchers = DB::table('users')
            ->whereIn('merchant_id', $merchantIds)
            ->where('role', 'dispatcher')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, COUNT(*) as count')
            ->get()
            ->keyBy('merchant_id');

        // Customer count per merchant
        $customerCounts = DB::table('customers')
            ->whereIn('merchant_id', $merchantIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, COUNT(*) as count')
            ->get()
            ->keyBy('merchant_id');

        // Last login per merchant (latest user login_at)
        $lastLogins = DB::table('users')
            ->whereIn('merchant_id', $merchantIds)
            ->whereNull('deleted_at')
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, MAX(last_login_at) as last_login_at')
            ->get()
            ->keyBy('merchant_id');

        $result = $merchants->map(function (Merchant $m) use (
            $lastOrders, $monthlyOrders, $successRates, $activeDrivers,
            $dispatchers, $customerCounts, $lastLogins
        ) {
            $sub       = $m->subscription;
            $subStatus = $sub?->status;

            $lo = $lastOrders[$m->id] ?? null;
            $lastOrderAt = $lo?->last_order_at;
            $daysSinceOrder = $lastOrderAt
                ? now()->diffInDays(\Carbon\Carbon::parse($lastOrderAt), false)
                : null;

            $sr = $successRates[$m->id] ?? null;
            $successRate = ($sr && $sr->terminal > 0)
                ? round(($sr->delivered / $sr->terminal) * 100, 1)
                : null;

            $health = $this->classifyHealth($subStatus, $daysSinceOrder, $successRate, $sub);

            return [
                'id'                  => $m->id,
                'company_name'        => $m->company_name,
                'email'               => $m->email,
                'subscription_status' => $subStatus,
                'plan_name'           => $sub?->plan?->name,
                'last_login_at'       => $lastLogins[$m->id]?->last_login_at,
                'last_order_at'       => $lastOrderAt,
                'monthly_orders'      => (int) ($monthlyOrders[$m->id]?->count ?? 0),
                'total_orders'        => (int) ($lo?->total_orders ?? 0),
                'active_drivers'      => (int) ($activeDrivers[$m->id]?->count ?? 0),
                'active_dispatchers'  => (int) ($dispatchers[$m->id]?->count ?? 0),
                'delivery_success_rate' => $successRate,
                'customer_count'      => (int) ($customerCounts[$m->id]?->count ?? 0),
                'health'              => $health,
                'created_at'          => $m->created_at,
            ];
        });

        $summary = [
            'healthy'           => $result->where('health', 'healthy')->count(),
            'needs_attention'   => $result->where('health', 'needs_attention')->count(),
            'inactive'          => $result->where('health', 'inactive')->count(),
        ];

        return response()->json([
            'data'    => $result->values(),
            'summary' => $summary,
        ]);
    }

    private function classifyHealth(?string $subStatus, ?float $daysSince, ?float $successRate, $sub): string
    {
        // Inactive if subscription is in terminal state
        if (in_array($subStatus, ['expired', 'cancelled', 'suspended'])) {
            return 'inactive';
        }

        // Inactive if no orders in 30+ days
        if ($daysSince !== null && $daysSince > 30) {
            return 'inactive';
        }

        // Healthy baseline requires recent order and decent success rate
        $isRecentlyActive = $daysSince !== null && $daysSince <= 7;
        $hasGoodRate      = $successRate === null || $successRate >= 75.0;
        $trialEndingSoon  = $subStatus === 'trial' && $sub?->trial_ends_at
            && \Carbon\Carbon::parse($sub->trial_ends_at)->diffInDays(now(), false) <= 3
            && \Carbon\Carbon::parse($sub->trial_ends_at)->isFuture();

        if ($trialEndingSoon) {
            return 'needs_attention';
        }

        if ($successRate !== null && $successRate < 40.0) {
            return 'inactive';
        }

        if ($successRate !== null && $successRate < 75.0) {
            return 'needs_attention';
        }

        if ($daysSince !== null && $daysSince > 7) {
            return 'needs_attention';
        }

        if ($isRecentlyActive && $hasGoodRate) {
            return 'healthy';
        }

        // Never ordered (new merchant)
        if ($daysSince === null) {
            return 'needs_attention';
        }

        return 'healthy';
    }

    // ─── PART 3: Platform Activity ────────────────────────────────────────────

    public function activity(Request $request): JsonResponse
    {
        $query = MerchantActivityLog::with(['actor:id,name', 'merchant:id,company_name'])
            ->when($request->merchant_id, fn($q, $id) => $q->where('merchant_id', $id))
            ->when($request->event_type, fn($q, $t) => $q->where('event_type', $t))
            ->when($request->search, fn($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('description', 'like', "%{$s}%")
                  ->orWhere('event_type', 'like', "%{$s}%");
            }))
            ->orderByDesc('created_at');

        $paginated = $query->paginate($request->per_page ?? 30);

        $paginated->getCollection()->transform(fn($entry) => [
            'id'            => $entry->id,
            'merchant_id'   => $entry->merchant_id,
            'merchant_name' => $entry->merchant?->company_name,
            'event_type'    => $entry->event_type,
            'description'   => $entry->description,
            'context'       => $entry->context,
            'actor_id'      => $entry->actor_id,
            'actor_name'    => $entry->actor?->name,
            'created_at'    => $entry->created_at,
        ]);

        return response()->json($paginated);
    }

    // ─── PART 4: Global Search ────────────────────────────────────────────────

    public function search(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['data' => ['merchants' => [], 'users' => [], 'applications' => [], 'subscriptions' => []]]);
        }

        $merchants = Merchant::select('id', 'company_name', 'email', 'phone')
            ->where(fn($q2) => $q2
                ->where('company_name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
            )
            ->limit(5)
            ->get()
            ->map(fn($m) => ['type' => 'merchant', 'id' => $m->id, 'label' => $m->company_name, 'sub' => $m->email, 'url' => "/admin/merchants/{$m->id}"]);

        $users = User::select('id', 'name', 'email', 'role', 'merchant_id')
            ->where(fn($q2) => $q2
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
            )
            ->limit(5)
            ->get()
            ->map(fn($u) => ['type' => 'user', 'id' => $u->id, 'label' => $u->name, 'sub' => "{$u->email} ({$u->role})", 'url' => $u->merchant_id ? "/admin/merchants/{$u->merchant_id}" : null]);

        $applications = \App\Models\MerchantApplication::select('id', 'company_name', 'email', 'status')
            ->where(fn($q2) => $q2
                ->where('company_name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
            )
            ->limit(5)
            ->get()
            ->map(fn($a) => ['type' => 'application', 'id' => $a->id, 'label' => $a->company_name, 'sub' => "{$a->email} · {$a->status}", 'url' => "/admin/applications/{$a->id}"]);

        $subscriptions = MerchantSubscription::select('merchant_subscriptions.id', 'merchants.company_name', 'merchant_subscriptions.status')
            ->join('merchants', 'merchants.id', '=', 'merchant_subscriptions.merchant_id')
            ->where('merchants.company_name', 'like', "%{$q}%")
            ->limit(5)
            ->get()
            ->map(fn($s) => ['type' => 'subscription', 'id' => $s->id, 'label' => $s->company_name, 'sub' => "Subscription · {$s->status}", 'url' => "/admin/subscriptions/{$s->id}"]);

        return response()->json([
            'data' => [
                'merchants'     => $merchants->values(),
                'users'         => $users->values(),
                'applications'  => $applications->values(),
                'subscriptions' => $subscriptions->values(),
            ],
        ]);
    }

    // ─── PART 5: Google API Analytics ────────────────────────────────────────

    public function googleApiAnalytics(): JsonResponse
    {
        $now = now();

        $today = DB::table('google_api_usage_logs')
            ->whereDate('created_at', $now->toDateString())
            ->selectRaw('COUNT(*) as requests, SUM(estimated_units) as units, SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits')
            ->first();

        $thisMonth = DB::table('google_api_usage_logs')
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->selectRaw('COUNT(*) as requests, SUM(estimated_units) as units, SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits')
            ->first();

        $topConsumers = DB::table('google_api_usage_logs as g')
            ->join('merchants as m', 'm.id', '=', 'g.merchant_id')
            ->whereMonth('g.created_at', $now->month)
            ->whereYear('g.created_at', $now->year)
            ->groupBy('g.merchant_id', 'm.company_name')
            ->selectRaw('g.merchant_id, m.company_name as merchant_name, COUNT(*) as requests, SUM(g.estimated_units) as estimated_units, SUM(CASE WHEN g.cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits')
            ->orderByDesc('requests')
            ->limit(10)
            ->get();

        // Daily trend for current month
        $dailyTrend = DB::table('google_api_usage_logs')
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->selectRaw("DATE(created_at) as date, COUNT(*) as requests, SUM(estimated_units) as estimated_units, SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        $calcRate = fn($obj) => ($obj->requests ?? 0) > 0
            ? round(($obj->cache_hits / $obj->requests) * 100, 1)
            : 0;

        return response()->json([
            'data' => [
                'today' => [
                    'requests'        => (int) ($today->requests ?? 0),
                    'estimated_units' => (int) ($today->units ?? 0),
                    'cache_hits'      => (int) ($today->cache_hits ?? 0),
                    'cache_hit_rate'  => $calcRate($today),
                ],
                'this_month' => [
                    'requests'        => (int) ($thisMonth->requests ?? 0),
                    'estimated_units' => (int) ($thisMonth->units ?? 0),
                    'cache_hits'      => (int) ($thisMonth->cache_hits ?? 0),
                    'cache_hit_rate'  => $calcRate($thisMonth),
                ],
                'top_consumers' => $topConsumers,
                'daily_trend'   => $dailyTrend,
            ],
        ]);
    }
}
