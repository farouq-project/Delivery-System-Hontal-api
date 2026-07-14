<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Merchant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardService
{
    public function __construct(private readonly FeatureManager $features) {}

    public function getDashboard(int $merchantId): array
    {
        $merchant  = Merchant::find($merchantId);
        $timezone  = $merchant?->timezone ?? config('app.timezone', 'Asia/Jakarta');
        $hasDomain = $this->features->isEnabled($merchantId, 'customer_domain');

        return [
            'operations_today'    => $this->operationsToday($merchantId, $timezone),
            'business_this_month' => $this->businessThisMonth($merchantId, $timezone),
            'customer_health'     => $this->customerHealth($merchantId, $timezone, $hasDomain),
            'cluster_summary'     => $this->clusterSummary($merchantId),
            'recent_activity'     => $this->recentActivity($merchantId),
            'requires_attention'  => $this->requiresAttention($merchantId, $hasDomain),
        ];
    }

    private function operationsToday(int $merchantId, string $timezone): array
    {
        $today = Carbon::today($timezone)->toDateString();

        $delivered = DeliveryOrder::where('merchant_id', $merchantId)
            ->where('status', 'delivered')
            ->whereDate('delivered_at', $today)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(order_value), 0) as revenue')
            ->first();

        $ordersToday = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereDate('order_created_at', $today)
            ->count();

        $activeDrivers = Driver::where('merchant_id', $merchantId)
            ->whereIn('status', ['available', 'on_delivery', 'delivering'])
            ->count();

        $terminal = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('status', ['delivered', 'failed', 'cancelled'])
            ->whereDate('order_created_at', $today)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered_cnt')
            ->first();

        $successRate = ($terminal->total ?? 0) > 0
            ? round(($terminal->delivered_cnt / $terminal->total) * 100, 1)
            : null;

        return [
            'revenue'              => (float) ($delivered->revenue ?? 0),
            'orders'               => $ordersToday,
            'deliveries_completed' => (int) ($delivered->cnt ?? 0),
            'active_drivers'       => $activeDrivers,
            'success_rate'         => $successRate,
        ];
    }

    private function businessThisMonth(int $merchantId, string $timezone): array
    {
        $startOfMonth = Carbon::now($timezone)->startOfMonth();
        $endOfMonth   = Carbon::now($timezone)->endOfMonth();

        $delivered = DeliveryOrder::where('merchant_id', $merchantId)
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(order_value), 0) as revenue')
            ->first();

        $monthlyOrders  = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereBetween('order_created_at', [$startOfMonth, $endOfMonth])
            ->count();

        $deliveredCnt   = (int) ($delivered->cnt ?? 0);
        $monthlyRevenue = (float) ($delivered->revenue ?? 0);
        $avgOrderValue  = $deliveredCnt > 0 ? round($monthlyRevenue / $deliveredCnt) : 0;

        // Customers with more than 1 delivered order all-time
        $repeatCustomers = DB::table('delivery_orders')
            ->where('merchant_id', $merchantId)
            ->where('status', 'delivered')
            ->whereNull('deleted_at')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $newThisMonth = Customer::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        $lastStart    = Carbon::now($timezone)->subMonth()->startOfMonth();
        $lastEnd      = Carbon::now($timezone)->subMonth()->endOfMonth();
        $newLastMonth = Customer::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$lastStart, $lastEnd])
            ->count();

        $growthPct = $newLastMonth > 0
            ? round((($newThisMonth - $newLastMonth) / $newLastMonth) * 100, 1)
            : ($newThisMonth > 0 ? 100.0 : 0.0);

        return [
            'revenue'             => $monthlyRevenue,
            'orders'              => $monthlyOrders,
            'avg_order_value'     => (float) $avgOrderValue,
            'repeat_customers'    => $repeatCustomers,
            'new_customers'       => $newThisMonth,
            'customer_growth_pct' => $growthPct,
        ];
    }

    private function customerHealth(int $merchantId, string $timezone, bool $hasDomain): array
    {
        $total        = Customer::where('merchant_id', $merchantId)->count();
        $startOfMonth = Carbon::now($timezone)->startOfMonth();
        $endOfMonth   = Carbon::now($timezone)->endOfMonth();

        $newThisMonth = Customer::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        $lastStart    = Carbon::now($timezone)->subMonth()->startOfMonth();
        $lastEnd      = Carbon::now($timezone)->subMonth()->endOfMonth();
        $newLastMonth = Customer::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$lastStart, $lastEnd])
            ->count();

        $growthPct = $newLastMonth > 0
            ? round((($newThisMonth - $newLastMonth) / $newLastMonth) * 100, 1)
            : ($newThisMonth > 0 ? 100.0 : 0.0);

        $repeat  = null;
        $dormant = null;

        if ($hasDomain) {
            $repeat = CustomerProfile::where('merchant_id', $merchantId)
                ->where('total_orders', '>', 1)
                ->count();

            $dormant = CustomerProfile::where('merchant_id', $merchantId)
                ->whereIn('health_status', ['dormant', 'lost'])
                ->count();
        }

        return [
            'total'          => $total,
            'new_this_month' => $newThisMonth,
            'repeat'         => $repeat,
            'dormant'        => $dormant,
            'growth_pct'     => $growthPct,
        ];
    }

    private function clusterSummary(int $merchantId): array
    {
        $rows = DB::table('delivery_orders as o')
            ->leftJoin('customers as c', function ($join) {
                $join->on('o.customer_id', '=', 'c.id')
                     ->whereNull('c.deleted_at');
            })
            ->where('o.merchant_id', $merchantId)
            ->whereNull('o.deleted_at')
            ->select([
                DB::raw('COALESCE(c.cluster, "No Cluster") as cluster'),
                DB::raw('COUNT(o.id) as total_orders'),
                DB::raw('COALESCE(SUM(CASE WHEN o.status = "delivered" THEN o.order_value ELSE 0 END), 0) as revenue'),
                DB::raw('SUM(CASE WHEN o.status = "delivered" THEN 1 ELSE 0 END) as deliveries'),
                DB::raw('SUM(CASE WHEN o.status IN ("delivered", "failed", "cancelled") THEN 1 ELSE 0 END) as terminal_cnt'),
            ])
            ->groupBy('c.cluster')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get();

        return $rows->map(fn($row) => [
            'cluster'      => $row->cluster,
            'total_orders' => (int) $row->total_orders,
            'revenue'      => (float) $row->revenue,
            'deliveries'   => (int) $row->deliveries,
            'success_rate' => (int) $row->terminal_cnt > 0
                ? round(((int) $row->deliveries / (int) $row->terminal_cnt) * 100, 1)
                : null,
        ])->values()->all();
    }

    private function recentActivity(int $merchantId): array
    {
        $orders = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('status', ['delivered', 'failed', 'cancelled', 'assigned', 'in_transit'])
            ->select(['id', 'order_number', 'customer_name', 'status', 'driver_id', 'updated_at'])
            ->with('driver:id,driver_name')
            ->orderByDesc('updated_at')
            ->limit(15)
            ->get();

        return $orders->map(fn($o) => [
            'id'            => $o->id,
            'order_number'  => $o->order_number,
            'customer_name' => $o->customer_name,
            'status'        => $o->status,
            'driver_name'   => $o->driver?->driver_name,
            'occurred_at'   => $o->updated_at?->toIso8601String(),
        ])->values()->all();
    }

    private function requiresAttention(int $merchantId, bool $hasDomain): array
    {
        $items = [];

        $delayed = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('status', ['assigned', 'in_transit'])
            ->where('order_created_at', '<', Carbon::now()->subHours(4))
            ->count();

        if ($delayed > 0) {
            $items[] = ['type' => 'delayed_deliveries', 'label' => 'Delayed Deliveries (>4h)', 'count' => $delayed, 'severity' => 'warning'];
        }

        $failedToday = DeliveryOrder::where('merchant_id', $merchantId)
            ->where('status', 'failed')
            ->whereDate('failed_at', Carbon::today())
            ->count();

        if ($failedToday > 0) {
            $items[] = ['type' => 'failed_today', 'label' => 'Failed Deliveries Today', 'count' => $failedToday, 'severity' => 'error'];
        }

        $missingGps = Customer::where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('default_latitude')
            ->count();

        if ($missingGps > 0) {
            $items[] = ['type' => 'missing_gps', 'label' => 'Customers Without GPS', 'count' => $missingGps, 'severity' => 'info'];
        }

        if ($hasDomain) {
            $dormant = CustomerProfile::where('merchant_id', $merchantId)
                ->whereIn('health_status', ['dormant', 'lost'])
                ->count();

            if ($dormant > 0) {
                $items[] = ['type' => 'dormant_customers', 'label' => 'Dormant / Lost Customers', 'count' => $dormant, 'severity' => 'warning'];
            }
        }

        $offlineDrivers = Driver::where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->where('status', 'offline')
            ->count();

        if ($offlineDrivers > 0) {
            $items[] = ['type' => 'offline_drivers', 'label' => 'Offline Drivers', 'count' => $offlineDrivers, 'severity' => 'info'];
        }

        return $items;
    }
}
