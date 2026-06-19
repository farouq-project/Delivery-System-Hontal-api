<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\OrderStatusHistory;
use App\Models\Route;
use App\Models\RouteAssignment;
use App\Models\RouteStop;
use App\Services\RoutingEngine\RoutingEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RouteController extends Controller
{
    public function __construct(private RoutingEngineService $engine) {}

    public function index(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $routes = Route::where('merchant_id', $merchantId)
            ->when($request->date,   fn($q, $d) => $q->where('route_date', $d))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->withCount('assignments')
            ->orderByDesc('route_date')
            ->paginate($request->per_page ?? 25);

        return response()->json($routes);
    }

    public function generate(Request $request)
    {
        $request->validate([
            'route_date' => 'required|date',
        ]);

        $merchant = $request->user()->merchant;
        $merchant->load('vipConfigs');

        try {
            $route = $this->engine->generate($merchant, $request->route_date);

            return response()->json(['data' => $this->loadFullRoute($route)], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Route generation failed: ' . $e->getMessage()], 500);
        }
    }

    public function show(Request $request, Route $route)
    {
        $this->authorizeMerchant($request, $route->merchant_id);

        return response()->json(['data' => $this->loadFullRoute($route)]);
    }

    private function loadFullRoute(Route $route): Route
    {
        $route->load(['assignments' => function ($q) {
            $q->with(['driver:id,driver_name,phone,status,current_lat,current_lng',
                      'stops' => fn($q) => $q->with('order:id,order_number,customer_name,delivery_address,delivery_latitude,delivery_longitude,status,requested_delivery_start,requested_delivery_end,product_name')->orderBy('stop_sequence')]);
        }]);

        return $route;
    }

    public function lock(Request $request, Route $route)
    {
        $this->authorizeMerchant($request, $route->merchant_id);
        $route->update(['locked_at' => now(), 'locked_by' => $request->user()->id]);
        return response()->json(['data' => $route->fresh()]);
    }

    public function unlock(Request $request, Route $route)
    {
        $this->authorizeMerchant($request, $route->merchant_id);
        $route->update(['locked_at' => null, 'locked_by' => null]);
        return response()->json(['data' => $route->fresh()]);
    }

    public function reset(Request $request, Route $route)
    {
        $this->authorizeMerchant($request, $route->merchant_id);

        $orderIds = RouteStop::where('route_id', $route->id)->pluck('order_id');

        DeliveryOrder::whereIn('id', $orderIds)
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled'])
            ->update(['status' => 'pending', 'driver_id' => null, 'assigned_at' => null, 'route_sequence' => null]);

        $route->delete();

        return response()->json(null, 204);
    }

    public function destroy(Request $request, Route $route)
    {
        $this->authorizeMerchant($request, $route->merchant_id);

        if (!in_array($request->user()->role, ['merchant_owner', 'super_admin', 'developer'])) {
            return response()->json(['message' => 'Only the merchant owner can delete a dispatch.'], 403);
        }

        // Delete without touching order statuses — orders keep their current state.
        // Use Reset Dispatch to also return orders to pending.
        $route->delete();

        return response()->json(null, 204);
    }

    public function reoptimize(Request $request, Route $route)
    {
        $this->authorizeMerchant($request, $route->merchant_id);

        $request->validate([
            'new_order_ids'    => 'nullable|array',
            'new_order_ids.*'  => 'integer|exists:delivery_orders,id',
        ]);

        $merchant = $request->user()->merchant;
        $merchant->load('vipConfigs');

        $newOrderIds = $request->new_order_ids ?? [];

        // Also pick up any pending orders not yet in route
        $existingOrderIds = RouteStop::where('route_id', $route->id)->pluck('order_id')->toArray();
        $autoNewOrders    = DeliveryOrder::where('merchant_id', $merchant->id)
            ->where('status', 'pending')
            ->whereNotNull('delivery_latitude')
            ->whereNotIn('id', $existingOrderIds)
            ->pluck('id')
            ->toArray();

        $allNewIds = array_unique(array_merge($newOrderIds, $autoNewOrders));

        if (empty($allNewIds)) {
            return response()->json(['message' => 'No new orders to insert.'], 422);
        }

        try {
            $route = $this->engine->reoptimize($route, $allNewIds);
            return response()->json(['data' => $route]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Reoptimization failed: ' . $e->getMessage()], 500);
        }
    }

    public function updateStop(Request $request, Route $route, RouteStop $stop)
    {
        $this->authorizeMerchant($request, $route->merchant_id);

        $request->validate([
            'new_sequence'         => 'nullable|integer|min:1',
            'route_assignment_id'  => 'nullable|integer|exists:route_assignments,id',
            'is_locked'            => 'nullable|boolean',
        ]);

        if ($request->has('new_sequence')) {
            $newSeq  = $request->new_sequence;
            $oldSeq  = $stop->stop_sequence;
            $asgmtId = $request->route_assignment_id ?? $stop->route_assignment_id;

            // Shift other stops
            if ($newSeq < $oldSeq) {
                RouteStop::where('route_assignment_id', $asgmtId)
                    ->whereBetween('stop_sequence', [$newSeq, $oldSeq - 1])
                    ->increment('stop_sequence');
            } elseif ($newSeq > $oldSeq) {
                RouteStop::where('route_assignment_id', $asgmtId)
                    ->whereBetween('stop_sequence', [$oldSeq + 1, $newSeq])
                    ->decrement('stop_sequence');
            }

            $stop->update([
                'stop_sequence'       => $newSeq,
                'route_assignment_id' => $asgmtId,
                'is_manually_placed'  => true,
            ]);
        }

        if ($request->has('is_locked')) {
            $stop->update(['is_locked' => $request->is_locked]);
        }

        return response()->json(['data' => $stop->fresh()]);
    }

    public function assignOrder(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $request->validate([
            'order_id'  => 'required|integer|exists:delivery_orders,id',
            'driver_id' => 'required|integer|exists:drivers,id',
        ]);

        $order  = DeliveryOrder::where('merchant_id', $merchantId)->findOrFail($request->order_id);
        $driver = Driver::where('merchant_id', $merchantId)->findOrFail($request->driver_id);

        $route = $this->assignOrderToDriver($request, $order, $driver);

        return response()->json(['data' => $this->loadFullRoute($route)]);
    }

    public function assignOrders(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:delivery_orders,id',
            'driver_id'   => 'required|integer|exists:drivers,id',
        ]);

        $driver = Driver::where('merchant_id', $merchantId)->findOrFail($request->driver_id);
        $orders = DeliveryOrder::where('merchant_id', $merchantId)->whereIn('id', $request->order_ids)->get();

        // Sort by route_sequence (routing priority) so stop_sequence is assigned in score order.
        // Orders without a route_sequence (unrouted) go last.
        $orders = $orders->sortBy(fn($o) => $o->route_sequence ?? PHP_INT_MAX)->values();

        $route = null;
        foreach ($orders as $order) {
            $route = $this->assignOrderToDriver($request, $order, $driver);
        }

        return response()->json(['data' => $this->loadFullRoute($route)]);
    }

    private function assignOrderToDriver(Request $request, DeliveryOrder $order, Driver $driver): Route
    {
        $routeDate = $order->requested_delivery_date ?? now()->format('Y-m-d');

        $route = Route::firstOrCreate(
            ['merchant_id' => $driver->merchant_id, 'route_date' => $routeDate],
            [
                'ulid'              => Str::ulid(),
                'status'            => 'draft',
                'generation_method' => 'manual',
                'generated_by'      => $request->user()->id,
                'generated_at'      => now(),
            ]
        );

        $assignment = RouteAssignment::firstOrCreate(
            ['route_id' => $route->id, 'driver_id' => $driver->id],
            ['sequence_number' => $route->assignments()->count() + 1]
        );

        // If the order already has a stop on this route (e.g. in the unassigned group), move it and
        // carry over scores so the driver's stop retains its routing priority.
        $existingStop = RouteStop::where('route_id', $route->id)->where('order_id', $order->id)->first();
        $scores = [];
        if ($existingStop) {
            $scores = [
                'distance_score' => $existingStop->distance_score,
                'waiting_score'  => $existingStop->waiting_score,
                'window_score'   => $existingStop->window_score,
                'vip_score'      => $existingStop->vip_score,
                'total_score'    => $existingStop->total_score,
            ];
            $oldAssignmentId = $existingStop->route_assignment_id;
            $oldSeq = $existingStop->stop_sequence;
            $existingStop->delete();
            RouteStop::where('route_assignment_id', $oldAssignmentId)
                ->where('stop_sequence', '>', $oldSeq)
                ->decrement('stop_sequence');
        }

        $nextSeq = (int) RouteStop::where('route_assignment_id', $assignment->id)->max('stop_sequence') + 1;

        RouteStop::create(array_merge([
            'route_id'            => $route->id,
            'route_assignment_id' => $assignment->id,
            'order_id'            => $order->id,
            'stop_sequence'       => $nextSeq,
            'is_manually_placed'  => true,
        ], $scores));

        if (!$existingStop) {
            $route->increment('total_stops');
        }

        $fromStatus = $order->status;
        $order->update(['driver_id' => $driver->id, 'status' => 'assigned', 'assigned_at' => now()]);

        try {
            OrderStatusHistory::create([
                'order_id'        => $order->id,
                'from_status'     => $fromStatus,
                'to_status'       => 'assigned',
                'changed_by'      => $request->user()->id,
                'changed_by_role' => $request->user()->role,
                'notes'           => "Assigned to driver #{$driver->id} via dispatch board",
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $route;
    }

    public function removeStop(Request $request, Route $route, RouteStop $stop)
    {
        $this->authorizeMerchant($request, $route->merchant_id);

        $asgmtId = $stop->route_assignment_id;
        $seq     = $stop->stop_sequence;

        // Move order back to pending
        DeliveryOrder::where('id', $stop->order_id)
            ->update(['status' => 'pending', 'driver_id' => null, 'route_sequence' => null]);

        $stop->delete();

        // Resequence remaining
        RouteStop::where('route_assignment_id', $asgmtId)
            ->where('stop_sequence', '>', $seq)
            ->decrement('stop_sequence');

        $route->decrement('total_stops');

        return response()->json(null, 204);
    }

    private function authorizeMerchant(Request $request, int $merchantId): void
    {
        if ($request->user()->merchant_id !== $merchantId && !$request->user()->isSuperAdmin()) {
            abort(403, 'Access denied.');
        }
    }
}
