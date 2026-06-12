<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Route;
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
            'route_date'  => 'required|date',
            'driver_ids'  => 'required|array|min:1',
            'driver_ids.*' => 'integer|exists:drivers,id',
            'order_ids'   => 'nullable|array',
            'order_ids.*' => 'integer|exists:delivery_orders,id',
        ]);

        $merchant = $request->user()->merchant;
        $merchant->load('vipConfigs');

        // If no orders specified, pick all pending for that date
        $orderIds = $request->order_ids;
        if (empty($orderIds)) {
            $orderIds = DeliveryOrder::where('merchant_id', $merchant->id)
                ->where('status', 'pending')
                ->where(function ($q) use ($request) {
                    $q->where('requested_delivery_date', $request->route_date)
                      ->orWhereNull('requested_delivery_date');
                })
                ->whereNotNull('delivery_latitude')
                ->pluck('id')
                ->toArray();
        }

        if (empty($orderIds)) {
            return response()->json(['message' => 'No pending orders found for this date.'], 422);
        }

        try {
            $route = $this->engine->generate(
                $merchant,
                $request->driver_ids,
                $orderIds,
                $request->route_date
            );

            return response()->json(['data' => $route], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Route generation failed: ' . $e->getMessage()], 500);
        }
    }

    public function show(Request $request, Route $route)
    {
        $this->authorizeMerchant($request, $route->merchant_id);

        $route->load(['assignments' => function ($q) {
            $q->with(['driver:id,driver_name,phone,status,current_lat,current_lng',
                      'stops' => fn($q) => $q->with('order:id,order_number,customer_name,delivery_address,delivery_latitude,delivery_longitude,status,requested_delivery_start,requested_delivery_end,product_name')->orderBy('stop_sequence')]);
        }]);

        return response()->json(['data' => $route]);
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
