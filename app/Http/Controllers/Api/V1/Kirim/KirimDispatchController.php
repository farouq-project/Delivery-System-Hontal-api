<?php

namespace App\Http\Controllers\Api\V1\Kirim;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\HontalKirimBatch;
use App\Models\PlatformSetting;
use App\Models\PooledRoute;
use App\Models\PooledStop;
use App\Models\DeliveryOrder;
use App\Models\RouteStop;
use App\Models\Scopes\MerchantScope;
use App\Services\KirimBatchService;
use App\Services\RoutingEngine\Clustering\GeographicClusterer;
use App\Services\RoutingEngine\Optimization\NearestNeighborSolver;
use App\Services\RoutingEngine\Optimization\TwoOptImprover;
use App\Services\RoutingEngine\Preprocessing\HaversineMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KirimDispatchController extends Controller
{
    public function __construct(
        private readonly KirimBatchService       $batchService,
        private readonly HaversineMatrix         $haversineMatrix,
        private readonly NearestNeighborSolver   $nnSolver,
        private readonly TwoOptImprover          $twoOpt,
        private readonly GeographicClusterer     $clusterer,
    ) {}

    // ── Batch queue ───────────────────────────────────────────────────────────

    public function batches(Request $request)
    {
        $batches = HontalKirimBatch::orderByDesc('window_start')
            ->limit(40)
            ->get();

        // Attach order counts and merchant breakdown per batch
        $batchIds = $batches->pluck('id');

        $orderCounts = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->whereIn('batch_id', $batchIds)
            ->selectRaw('batch_id, merchant_id, count(*) as cnt')
            ->groupBy('batch_id', 'merchant_id')
            ->with('merchant:id,company_name')
            ->get()
            ->groupBy('batch_id');

        $routeCounts = PooledRoute::whereIn('batch_id', $batchIds)
            ->selectRaw('batch_id, count(*) as cnt')
            ->groupBy('batch_id')
            ->pluck('cnt', 'batch_id');

        $data = $batches->map(function (HontalKirimBatch $batch) use ($orderCounts, $routeCounts) {
            $batchOrders = $orderCounts->get($batch->id, collect());
            $merchants   = $batchOrders->map(fn($row) => [
                'merchant_id'   => $row->merchant_id,
                'company_name'  => $row->merchant?->company_name ?? '—',
                'order_count'   => $row->cnt,
            ])->values();

            return [
                'id'           => $batch->id,
                'window_start' => $batch->window_start,
                'window_end'   => $batch->window_end,
                'status'       => $batch->status,
                'closed_at'    => $batch->closed_at,
                'order_count'  => $batchOrders->sum('cnt'),
                'route_count'  => $routeCounts->get($batch->id, 0),
                'merchants'    => $merchants,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function batchOrders(Request $request, HontalKirimBatch $batch)
    {
        $orders = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->where('batch_id', $batch->id)
            ->with([
                'merchant:id,company_name',
                'depot:id,name,address,latitude,longitude',
                'pooledStop:id,pooled_route_id,stop_sequence,status',
            ])
            ->orderBy('order_created_at')
            ->get()
            ->map(fn($o) => [
                'id'                  => $o->id,
                'order_number'        => $o->order_number,
                'merchant_id'         => $o->merchant_id,
                'merchant_name'       => $o->merchant?->company_name,
                'customer_name'       => $o->customer_name,
                'delivery_address'    => $o->delivery_address,
                'delivery_latitude'   => $o->delivery_latitude,
                'delivery_longitude'  => $o->delivery_longitude,
                'product_name'        => $o->product_name,
                'status'              => $o->status,
                'depot'               => $o->depot,
                'assigned_to_route'   => $o->pooledStop?->pooled_route_id,
            ]);

        return response()->json([
            'data'  => $orders,
            'batch' => [
                'id'           => $batch->id,
                'window_start' => $batch->window_start,
                'window_end'   => $batch->window_end,
                'status'       => $batch->status,
            ],
        ]);
    }

    // ── Batch management ──────────────────────────────────────────────────────

    public function closeBatch(Request $request, HontalKirimBatch $batch)
    {
        if ($batch->status !== 'open') {
            return response()->json(['message' => 'Batch is already closed.'], 422);
        }

        $batch->update([
            'status'    => 'closed',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $batch->fresh()]);
    }

    // ── Route builder ─────────────────────────────────────────────────────────

    public function drivers(Request $request)
    {
        // Primary: use the configured internal merchant ID
        $internalMerchantId = (int) (PlatformSetting::where('key', 'hontal_internal_merchant_id')->value('value') ?: 0);

        // Fallback: find any merchant with merchant_type = 'internal'
        if (!$internalMerchantId) {
            $internalMerchantId = \App\Models\Merchant::where('merchant_type', 'internal')->value('id') ?: 0;
        }

        // Show all active mitra drivers regardless of current status — dispatcher decides who to assign
        $drivers = Driver::withoutGlobalScope(MerchantScope::class)
            ->where('merchant_id', $internalMerchantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->orderBy('driver_name')
            ->get()
            ->map(fn($d) => [
                'id'            => $d->id,
                'name'          => $d->user?->name ?? $d->driver_name ?? '—',
                'vehicle_type'  => $d->vehicle_type,
                'vehicle_plate' => $d->vehicle_plate,
                'status'        => $d->status,
            ]);

        return response()->json(['data' => $drivers]);
    }

    public function previewRoute(Request $request)
    {
        $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:delivery_orders,id',
        ]);

        $orders = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->whereIn('id', $request->order_ids)
            ->with('depot')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(['data' => ['ordered_ids' => [], 'origin' => null]]);
        }

        $merchantDepot = null;
        $originInfo    = null;

        $kirimMerchants = \App\Models\Merchant::withoutGlobalScope(MerchantScope::class)
            ->with('settings')
            ->whereIn('id', $orders->pluck('merchant_id')->unique()->values())
            ->get();

        foreach ($kirimMerchants as $m) {
            if ($m->settings?->depot_latitude && $m->settings?->depot_longitude) {
                $merchantDepot = [
                    'lat' => (float) $m->settings->depot_latitude,
                    'lng' => (float) $m->settings->depot_longitude,
                ];
                $originInfo = array_merge($merchantDepot, [
                    'name'    => $m->company_name,
                    'address' => $m->settings->depot_address,
                ]);
                break;
            }
        }

        $locatedOrders = $orders->filter(fn($o) => $o->delivery_latitude && $o->delivery_longitude);
        $orderedIds    = $this->optimizeDeliveries($orders, $locatedOrders, $merchantDepot);

        return response()->json([
            'data' => [
                'ordered_ids' => $orderedIds,
                'origin'      => $originInfo,
            ],
        ]);
    }

    public function createRoute(Request $request)
    {
        $data = $request->validate([
            'driver_id'   => 'required|integer|exists:drivers,id',
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:delivery_orders,id',
            'notes'       => 'nullable|string|max:500',
        ]);

        $driver = Driver::withoutGlobalScope(MerchantScope::class)->findOrFail($data['driver_id']);

        // Accept pending, batched, AND assigned orders — assigned orders are
        // auto-unassigned from their previous pooled/sistem routes before the
        // new route is built. This handles merchants that switched types and
        // have orders stuck in 'assigned' state from an old route.
        $orders = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->whereIn('id', $data['order_ids'])
            ->whereIn('status', ['pending', 'batched', 'assigned'])
            ->with(['depot'])
            ->get();

        if ($orders->count() !== count($data['order_ids'])) {
            return response()->json(['message' => 'One or more orders are not available (wrong status or not found).'], 422);
        }

        // Auto-unassign any orders that are currently 'assigned' from old routes
        $stuckOrders = $orders->where('status', 'assigned');
        if ($stuckOrders->isNotEmpty()) {
            DB::transaction(function () use ($stuckOrders) {
                foreach ($stuckOrders as $order) {
                    $this->detachOrderFromRoutes($order);
                }
            });
            // Refresh collection so status reflects 'pending'
            $orders = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
                ->whereIn('id', $data['order_ids'])
                ->with(['depot'])
                ->get();
        }

        // Use the batch_id from hontal_kirim orders; null is fine for merchant_managed orders
        $batchId = $orders->firstWhere('batch_id', '!=', null)?->batch_id;

        // Optimize delivery stop sequence using NN + 2-opt before building the route
        $locatedOrders   = $orders->filter(fn($o) => $o->delivery_latitude && $o->delivery_longitude);
        $unlocatedOrders = $orders->reject(fn($o) => $o->delivery_latitude && $o->delivery_longitude);

        // Resolve routing origin: prefer merchant operational depot (Settings → Operational)
        // over order-level depot_id, which is null for regular (non-Kirim-form) orders
        $merchantDepot = null;
        $kirimMerchants = \App\Models\Merchant::withoutGlobalScope(MerchantScope::class)
            ->with('settings')
            ->whereIn('id', $orders->pluck('merchant_id')->unique()->values())
            ->get();
        foreach ($kirimMerchants as $m) {
            if ($m->settings?->depot_latitude && $m->settings?->depot_longitude) {
                $merchantDepot = [
                    'lat' => (float) $m->settings->depot_latitude,
                    'lng' => (float) $m->settings->depot_longitude,
                ];
                break;
            }
        }

        $orderedDeliveryIds = $this->optimizeDeliveries($orders, $locatedOrders, $merchantDepot);

        foreach ($unlocatedOrders->pluck('id') as $id) {
            if (!in_array($id, $orderedDeliveryIds)) {
                $orderedDeliveryIds[] = $id;
            }
        }

        $depotGroups = $orders->groupBy('depot_id');
        $orderMap    = $orders->keyBy('id');
        $stops = [];
        $seq   = 1;

        // Pickup stops first — one per unique depot
        foreach ($depotGroups as $depotId => $depotOrders) {
            if (!$depotId) continue;
            $depot = $depotOrders->first()->depot;
            if (!$depot) continue;
            $stops[] = [
                'seq'           => $seq++,
                'type'          => 'pickup',
                'depot_id'      => $depotId,
                'order_ids'     => $depotOrders->pluck('id')->toArray(),
                'latitude'      => $depot->latitude ?? 0,
                'longitude'     => $depot->longitude ?? 0,
                'contact_name'  => $depot->contact_name,
                'contact_phone' => $depot->contact_phone,
            ];
        }

        // Delivery stops in NN+2-opt optimized sequence
        foreach ($orderedDeliveryIds as $orderId) {
            $order = $orderMap[$orderId] ?? null;
            if (!$order) continue;
            $stops[] = [
                'seq'           => $seq++,
                'type'          => 'dropoff',
                'order_id'      => $order->id,
                'latitude'      => $order->delivery_latitude,
                'longitude'     => $order->delivery_longitude,
                'contact_name'  => $order->customer_name,
                'contact_phone' => $order->customer_phone,
            ];
        }

        $route = DB::transaction(function () use ($batchId, $driver, $stops, $orders, $data, $request) {
            $route = PooledRoute::create([
                'batch_id'    => $batchId,
                'driver_id'   => $driver->id,
                'status'      => 'queued',
                'total_stops' => count($stops),
                'created_by'  => $request->user()->id,
                'assigned_at' => now(),
                'notes'       => $data['notes'] ?? null,
            ]);

            // Track dropoff stop sequence per order so we can set route_sequence
            $orderSeqMap = [];

            foreach ($stops as $stop) {
                $pooledStop = PooledStop::create([
                    'pooled_route_id' => $route->id,
                    'stop_sequence'   => $stop['seq'],
                    'stop_type'       => $stop['type'],
                    'depot_id'        => $stop['depot_id'] ?? null,
                    'order_id'        => $stop['order_id'] ?? null,
                    'latitude'        => $stop['latitude'],
                    'longitude'       => $stop['longitude'],
                    'contact_name'    => $stop['contact_name'],
                    'contact_phone'   => $stop['contact_phone'],
                    'status'          => 'pending',
                ]);

                if ($stop['type'] === 'pickup') {
                    DB::table('pooled_stop_orders')->insert(
                        collect($stop['order_ids'])->map(fn($oid) => [
                            'pooled_stop_id' => $pooledStop->id,
                            'order_id'       => $oid,
                        ])->toArray()
                    );
                } elseif ($stop['type'] === 'dropoff') {
                    $orderSeqMap[$stop['order_id']] = $stop['seq'];
                }
            }

            // Write driver_id + route_sequence on each order so the merchant's
            // klotter page, live map, and driver app can query them normally.
            DeliveryOrder::withoutGlobalScope(MerchantScope::class)
                ->whereIn('id', $orders->pluck('id'))
                ->update([
                    'status'      => 'assigned',
                    'assigned_at' => now(),
                    'driver_id'   => $driver->id,
                ]);

            // Individual route_sequence per order (can't be done in one bulk update)
            foreach ($orderSeqMap as $orderId => $seq) {
                DeliveryOrder::withoutGlobalScope(MerchantScope::class)
                    ->where('id', $orderId)
                    ->update(['route_sequence' => $seq]);
            }

            return $route;
        });

        return response()->json([
            'data' => $route->load(['stops', 'driver.user']),
        ], 201);
    }

    /**
     * Remove an order from any existing sistem (RouteStop) or Kirim (PooledStop)
     * dispatch records and reset its status to pending. Called inside a DB
     * transaction when reassigning 'assigned' orders to a new pooled route.
     */
    private function detachOrderFromRoutes(DeliveryOrder $order): void
    {
        // Sistem layer
        $routeStop = RouteStop::where('order_id', $order->id)->first();
        if ($routeStop) {
            $route = $routeStop->route;
            RouteStop::where('route_assignment_id', $routeStop->route_assignment_id)
                ->where('stop_sequence', '>', $routeStop->stop_sequence)
                ->decrement('stop_sequence');
            $routeStop->delete();
            $route?->decrement('total_stops');
        }

        // Kirim layer — dropoff stop
        $pooledStop = PooledStop::where('order_id', $order->id)->first();
        if ($pooledStop) {
            $pooledRoute = $pooledStop->route;
            DB::table('pooled_stop_orders')->where('order_id', $order->id)->delete();
            PooledStop::where('pooled_route_id', $pooledStop->pooled_route_id)
                ->where('stop_sequence', '>', $pooledStop->stop_sequence)
                ->decrement('stop_sequence');
            $pooledStop->delete();
            if ($pooledRoute) {
                $remaining = $pooledRoute->stops()->count();
                $pooledRoute->update($remaining === 0
                    ? ['status' => 'cancelled', 'total_stops' => 0]
                    : ['total_stops' => $remaining]
                );
            }
        }

        // Kirim layer — pickup pivot (order listed in a pickup stop but has no direct pooled_stop row)
        DB::table('pooled_stop_orders')->where('order_id', $order->id)->delete();

        $order->update(['status' => 'pending', 'driver_id' => null, 'assigned_at' => null, 'route_sequence' => null]);
    }

    /**
     * Origin priority:
     *   1. $merchantDepot — merchant's operational depot from MerchantSetting (covers regular orders with no depot_id)
     *   2. First order-level depot record with valid coordinates
     *   3. Centroid of all located delivery points (last resort)
     */
    private function optimizeDeliveries(Collection $allOrders, Collection $locatedOrders, ?array $merchantDepot = null): array
    {
        if ($locatedOrders->isEmpty()) {
            return $allOrders->pluck('id')->toArray();
        }

        $origin = $merchantDepot;

        if (!$origin) {
            foreach ($allOrders->groupBy('depot_id') as $depotId => $depotOrders) {
                if (!$depotId) continue;
                $depot = $depotOrders->first()->depot;
                if ($depot && $depot->latitude && $depot->longitude) {
                    $origin = ['lat' => (float) $depot->latitude, 'lng' => (float) $depot->longitude];
                    break;
                }
            }
        }
        if (!$origin) {
            $origin = [
                'lat' => (float) $locatedOrders->avg('delivery_latitude'),
                'lng' => (float) $locatedOrders->avg('delivery_longitude'),
            ];
        }

        // Build haversine matrix: index 0 = origin, 1..N = deliveries
        $allPoints = [$origin];
        $indexMap  = [];
        $stopsData = [];

        foreach ($locatedOrders as $order) {
            $allPoints[]          = ['lat' => (float) $order->delivery_latitude, 'lng' => (float) $order->delivery_longitude];
            $indexMap[$order->id] = count($allPoints) - 1;
            $stopsData[$order->id] = [
                'lat'         => (float) $order->delivery_latitude,
                'lng'         => (float) $order->delivery_longitude,
                'total_score' => 0,
            ];
        }

        // Assign geographic cluster keys for group-affinity routing
        $clusterMap = $this->clusterer->clusterStops($stopsData);
        foreach ($stopsData as $id => $_) {
            $stopsData[$id]['group_key'] = $clusterMap[$id] ?? null;
        }

        $matrix  = $this->haversineMatrix->build($allPoints);
        $ordered = $this->nnSolver->solveGrouped($stopsData, $matrix, $indexMap);
        $ordered = $this->twoOpt->improve($ordered, $matrix, $indexMap);

        return $ordered;
    }

    public function routeDetail(PooledRoute $route)
    {
        return response()->json([
            'data' => $route->load([
                'batch:id,window_start,window_end,status',
                'driver.user:id,name',
                'stops.order:id,order_number,customer_name,delivery_address,merchant_id',
                'stops.depot:id,name,address',
                'stops.pickupOrders:id,order_number,customer_name,delivery_address',
            ]),
        ]);
    }

    // ── Delivery date queue ───────────────────────────────────────────────────

    public function deliveryDates()
    {
        $kirimIds = \App\Models\Merchant::where('merchant_type', 'kirim')->pluck('id');

        $rows = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->whereIn('merchant_id', $kirimIds)
            ->whereNotIn('status', ['cancelled', 'failed', 'delivered'])
            ->whereNotNull('requested_delivery_date')
            ->selectRaw("DATE(requested_delivery_date) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('pending','batched') THEN 1 ELSE 0 END) as unassigned")
            ->groupByRaw('DATE(requested_delivery_date)')
            ->orderByRaw('DATE(requested_delivery_date)')
            ->get();

        $today    = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $data = $rows->map(fn($r) => [
            'date'       => $r->date,
            'label'      => match($r->date) {
                $today    => 'Today',
                $tomorrow => 'Tomorrow',
                default   => \Carbon\Carbon::parse($r->date)->isoFormat('ddd, D MMM'),
            },
            'total'      => (int) $r->total,
            'unassigned' => (int) $r->unassigned,
        ]);

        return response()->json(['data' => $data]);
    }

    public function ordersByDate(Request $request)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);

        $kirimIds = \App\Models\Merchant::where('merchant_type', 'kirim')->pluck('id');

        $orders = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->whereIn('merchant_id', $kirimIds)
            ->whereDate('requested_delivery_date', $data['date'])
            ->with([
                'merchant:id,company_name',
                'depot:id,name,address,latitude,longitude',
                'pooledStop:id,pooled_route_id,stop_sequence,status',
            ])
            ->orderBy('order_created_at')
            ->get()
            ->map(fn($o) => [
                'id'                  => $o->id,
                'order_number'        => $o->order_number,
                'batch_id'            => $o->batch_id,
                'merchant_id'         => $o->merchant_id,
                'merchant_name'       => $o->merchant?->company_name,
                'customer_name'       => $o->customer_name,
                'delivery_address'    => $o->delivery_address,
                'delivery_latitude'   => $o->delivery_latitude,
                'delivery_longitude'  => $o->delivery_longitude,
                'product_name'        => $o->product_name,
                'status'              => $o->status,
                'depot'               => $o->depot,
                'assigned_to_route'   => $o->pooledStop?->pooled_route_id,
            ]);

        return response()->json(['data' => $orders]);
    }

    // ── Active routes ─────────────────────────────────────────────────────────

    public function activeRoutes()
    {
        $routes = PooledRoute::whereIn('status', ['queued', 'active'])
            ->with([
                'driver:id,merchant_id,driver_name,vehicle_plate,current_lat,current_lng,user_id',
                'driver.user:id,name',
                'batch:id,window_start,window_end,status',
                'stops:id,pooled_route_id,stop_type,stop_sequence,status,order_id,depot_id,latitude,longitude,contact_name',
                'stops.order:id,order_number,delivery_address',
            ])
            ->orderByDesc('assigned_at')
            ->limit(30)
            ->get()
            ->map(fn($r) => [
                'id'              => $r->id,
                'status'          => $r->status,
                'driver_name'     => $r->driver?->user?->name ?? $r->driver?->driver_name ?? '—',
                'vehicle_plate'   => $r->driver?->vehicle_plate ?? '—',
                'driver_lat'      => $r->driver?->current_lat ? (float) $r->driver->current_lat : null,
                'driver_lng'      => $r->driver?->current_lng ? (float) $r->driver->current_lng : null,
                'total_stops'     => $r->total_stops,
                'completed_stops' => $r->completed_stops,
                'assigned_at'     => $r->assigned_at,
                'started_at'      => $r->started_at,
                'batch'           => $r->batch ? [
                    'id'           => $r->batch->id,
                    'window_start' => $r->batch->window_start,
                    'window_end'   => $r->batch->window_end,
                ] : null,
                'stop_progress'   => $r->stops->groupBy('status')->map->count(),
                'stops'           => $r->stops->sortBy('stop_sequence')->map(fn($s) => [
                    'id'           => $s->id,
                    'seq'          => $s->stop_sequence,
                    'type'         => $s->stop_type,
                    'status'       => $s->status,
                    'lat'          => $s->latitude ? (float) $s->latitude : null,
                    'lng'          => $s->longitude ? (float) $s->longitude : null,
                    'label'        => $s->contact_name ?? '—',
                    'order_number' => $s->order?->order_number,
                    'address'      => $s->order?->delivery_address,
                ])->values(),
            ]);

        return response()->json(['data' => $routes]);
    }

    public function cancelRoute(Request $request, PooledRoute $route)
    {
        if (!in_array($route->status, ['queued', 'active'])) {
            return response()->json(['message' => 'Only queued or active routes can be cancelled.'], 422);
        }

        DB::transaction(function () use ($route) {
            // Collect stop IDs and all affected order IDs before deleting
            $stopIds = $route->stops()->pluck('id');

            $dropoffOrderIds = $route->stops()->whereNotNull('order_id')->pluck('order_id');
            $pickupOrderIds  = DB::table('pooled_stop_orders')->whereIn('pooled_stop_id', $stopIds)->pluck('order_id');
            $allOrderIds     = $dropoffOrderIds->merge($pickupOrderIds)->unique()->filter();

            // Clean pivot first, then stops
            DB::table('pooled_stop_orders')->whereIn('pooled_stop_id', $stopIds)->delete();
            $route->stops()->delete();

            // Return all orders to pending with no driver assignment
            if ($allOrderIds->isNotEmpty()) {
                DeliveryOrder::withoutGlobalScope(MerchantScope::class)
                    ->whereIn('id', $allOrderIds)
                    ->update(['status' => 'pending', 'driver_id' => null, 'assigned_at' => null]);
            }

            $route->update(['status' => 'cancelled', 'total_stops' => 0]);
        });

        return response()->json(['message' => 'Route reset successfully.']);
    }

    // ── Top-up (admin) ────────────────────────────────────────────────────────

    public function topup(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => 'required|integer|exists:merchants,id',
            'amount_idr'  => 'required|integer|min:10000|max:100000000',
            'note'        => 'nullable|string|max:500',
        ]);

        $credit = app(\App\Services\KirimCreditService::class)
            ->topup($data['merchant_id'], $data['amount_idr'], $data['note'] ?? 'Manual top-up by dispatcher', $request->user()->id);

        return response()->json(['data' => $credit]);
    }
}
