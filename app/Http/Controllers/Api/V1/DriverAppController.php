<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\StopCompleted;
use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\MerchantSetting;
use App\Models\PooledRoute;
use App\Models\PooledStop;
use App\Models\ProofOfDelivery;
use App\Models\RouteStop;
use App\Models\Scopes\MerchantScope;
use App\Services\OrderService;
use Illuminate\Http\Request;

class DriverAppController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function me(Request $request)
    {
        $user   = $request->user();
        $driver = Driver::where('user_id', $user->id)->first();

        if (!$driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $todayTotal     = DeliveryOrder::where('driver_id', $driver->id)->whereDate('requested_delivery_date', today())->count();
        $todayCompleted = DeliveryOrder::where('driver_id', $driver->id)->whereDate('requested_delivery_date', today())->where('status', 'delivered')->count();

        return response()->json([
            'data' => [
                'driver'          => $driver,
                'today_total'     => $todayTotal,
                'today_completed' => $todayCompleted,
                'today_remaining' => $todayTotal - $todayCompleted,
            ],
        ]);
    }

    public function today(Request $request)
    {
        $driver      = $this->getDriver($request);
        $canLogout   = (bool) ($request->user()->can_logout ?? true);
        $merchantId  = $request->user()->merchant_id;
        $klotterSize = MerchantSetting::where('merchant_id', $merchantId)->value('klotter_size') ?? 7;

        $assignment = $driver->routeAssignments()
            ->whereHas('route', fn($q) => $q->where('route_date', today()))
            ->with(['stops' => function ($q) {
                $q->with('order:id,order_number,customer_name,customer_phone,delivery_address,delivery_latitude,delivery_longitude,delivery_notes,product_name,order_value,payment_method,assigned_at,status,requested_delivery_start,requested_delivery_end')
                  ->orderByDesc('total_score')
                  ->orderBy('stop_sequence');
            }])
            ->first();

        if (!$assignment) {
            return response()->json(['data' => ['stops' => [], 'total_stops' => 0, 'completed_stops' => 0, 'can_logout' => $canLogout, 'klotter_size' => $klotterSize]]);
        }

        // Pooled route (Kirim) — separate from Sistem klotter stops.
        // Allow null batch_id (merchant_managed orders routed via kirim dispatch).
        $pooledRoute = PooledRoute::withoutGlobalScope(MerchantScope::class)
            ->where('driver_id', $driver->id)
            ->whereIn('status', ['queued', 'active'])
            ->where(function ($q) {
                $q->whereNull('batch_id')
                  ->orWhereHas('batch', fn($q) => $q->whereDate('window_start', today()));
            })
            ->with(['stops' => function ($q) {
                $q->with([
                    'order:id,order_number,customer_name,customer_phone,delivery_address,delivery_latitude,delivery_longitude,delivery_notes,product_name,order_value,payment_method,status',
                    'depot:id,name,address,latitude,longitude,contact_name,contact_phone',
                    'pickupOrders:id,order_number,customer_name,product_name',
                ])->orderBy('stop_sequence');
            }, 'batch:id,window_start,window_end'])
            ->latest('assigned_at')
            ->first();

        $pooledRouteData = null;
        if ($pooledRoute) {
            $pooledRouteData = [
                'route_id'       => $pooledRoute->id,
                'status'         => $pooledRoute->status,
                'total_stops'    => $pooledRoute->total_stops,
                'completed_stops'=> $pooledRoute->completed_stops,
                'batch_window'   => $pooledRoute->batch?->window_start?->toISOString(),
                'stops'          => $pooledRoute->stops->map(fn($s) => [
                    'stop_id'         => $s->id,
                    'stop_sequence'   => $s->stop_sequence,
                    'stop_type'       => $s->stop_type,
                    'status'          => $s->status,
                    'latitude'        => $s->latitude,
                    'longitude'       => $s->longitude,
                    'contact_name'    => $s->contact_name,
                    'contact_phone'   => $s->contact_phone,
                    'depot'           => $s->depot,
                    'order'           => $s->order,
                    'pickup_orders'   => $s->stop_type === 'pickup' ? $s->pickupOrders : null,
                ]),
            ];
        }

        return response()->json([
            'data' => [
                'can_logout'          => $canLogout,
                'klotter_size'        => $klotterSize,
                'route_assignment_id' => $assignment->id,
                'route_date'          => today()->toDateString(),
                'total_stops'         => $assignment->total_stops,
                'completed_stops'     => $assignment->completed_stops,
                'failed_stops'        => $assignment->failed_stops,
                'remaining_stops'     => $assignment->total_stops - $assignment->completed_stops - $assignment->failed_stops,
                'stops'               => $assignment->stops->map(fn($s) => [
                    'stop_id'           => $s->id,
                    'stop_sequence'     => $s->stop_sequence,
                    'order_id'          => $s->order_id,
                    'is_locked'         => $s->is_locked,
                    'estimated_arrival' => $s->estimated_arrival?->toISOString(),
                    'order'             => $s->order,
                ]),
                'pooled_route'        => $pooledRouteData,
            ],
        ]);
    }

    public function updateLocation(Request $request)
    {
        $request->validate([
            'latitude'    => 'required|numeric|between:-90,90',
            'longitude'   => 'required|numeric|between:-180,180',
            'accuracy_m'  => 'nullable|numeric',
            'speed_kmh'   => 'nullable|numeric',
            'bearing_deg' => 'nullable|numeric',
            'battery_pct' => 'nullable|integer|between:0,100',
        ]);

        $driver = $this->getDriver($request);

        $driver->update([
            'current_lat' => $request->latitude,
            'current_lng' => $request->longitude,
            'last_seen'   => now(),
        ]);

        DriverLocation::create([
            'driver_id'   => $driver->id,
            'merchant_id' => $driver->merchant_id,
            'latitude'    => $request->latitude,
            'longitude'   => $request->longitude,
            'accuracy_m'  => $request->accuracy_m,
            'speed_kmh'   => $request->speed_kmh,
            'bearing_deg' => $request->bearing_deg,
            'battery_pct' => $request->battery_pct,
            'recorded_at' => now(),
        ]);

        return response()->json(['message' => 'ok']);
    }

    public function updateStatus(Request $request)
    {
        $request->validate(['status' => 'required|in:available,delivering,offline']);
        $driver = $this->getDriver($request);
        $driver->update(['status' => $request->status]);
        return response()->json(['message' => 'ok']);
    }

    public function deliver(Request $request, int $stopId)
    {
        $driver = $this->getDriver($request);
        $stop   = RouteStop::with('order')->findOrFail($stopId);
        $this->authorizeStop($stop, $driver);

        $request->validate([
            'latitude'       => 'nullable|numeric',
            'longitude'      => 'nullable|numeric',
            'recipient_name' => 'nullable|string|max:255',
            'notes'          => 'nullable|string',
            'photo'          => 'nullable|file|max:20480',
        ]);

        $order = $stop->order;

        $photoPath = null;
        if ($request->hasFile('photo')) {
            try {
                $photoPath = $request->file('photo')->store('pods', 'public');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        ProofOfDelivery::updateOrCreate(
            ['order_id' => $order->id],
            [
                'driver_id'          => $driver->id,
                'photo_path'         => $photoPath,
                'captured_latitude'  => $request->latitude,
                'captured_longitude' => $request->longitude,
                'recipient_name'     => $request->recipient_name,
                'notes'              => $request->notes,
                'captured_at'        => now(),
            ]
        );

        $this->orderService->transition($order, 'delivered', $request->user(), [
            'latitude'  => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        // Post-delivery ops: if any fail, delivery is already persisted — return 200
        try {
            $stop->update(['actual_arrival' => now()]);

            $assignment = $stop->assignment;
            $assignment->increment('completed_stops');

            $remaining = RouteStop::where('route_assignment_id', $assignment->id)
                ->whereHas('order', fn($q) => $q->whereNotIn('status', ['delivered', 'failed']))
                ->count();

            if ($remaining === 0) {
                $assignment->update(['status' => 'completed', 'actual_end_at' => now()]);
                $driver->update(['status' => 'available']);
            }

            event(new StopCompleted($stop, $order->fresh(), $driver, $request->user(), 'delivered'));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Delivery confirmed.']);
    }

    public function fail(Request $request, int $stopId)
    {
        $driver = $this->getDriver($request);
        $stop   = RouteStop::with('order')->findOrFail($stopId);
        $this->authorizeStop($stop, $driver);

        $request->validate([
            'reason'    => 'required|string|max:500',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $order = $stop->order;

        $this->orderService->transition($order, 'failed', $request->user(), [
            'reason'    => $request->reason,
            'latitude'  => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        try {
            $assignment = $stop->assignment;
            $assignment->increment('failed_stops');

            event(new StopCompleted($stop, $order->fresh(), $driver, $request->user(), 'failed'));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Failure reported.']);
    }

    public function completePooledStop(Request $request, int $stopId)
    {
        $driver = $this->getDriver($request);
        $stop   = PooledStop::with('route')->findOrFail($stopId);

        if ($stop->route->driver_id !== $driver->id) {
            abort(403, 'This stop is not assigned to you.');
        }
        if ($stop->status !== 'pending') {
            return response()->json(['message' => 'Stop already completed.'], 422);
        }

        $stop->update(['status' => 'completed', 'actual_arrival' => now()]);

        // For dropoff stops: mark the linked order as delivered
        if ($stop->isDropoff() && $stop->order_id) {
            $order = DeliveryOrder::withoutGlobalScope(MerchantScope::class)->find($stop->order_id);
            if ($order) {
                $this->orderService->transition($order, 'delivered', $request->user(), [
                    'latitude'  => $request->latitude,
                    'longitude' => $request->longitude,
                ]);
            }
        }

        $route = $stop->route;
        $route->increment('completed_stops');

        // Auto-complete route when all stops done
        $pending = PooledStop::where('pooled_route_id', $route->id)->where('status', 'pending')->count();
        if ($pending === 0) {
            $route->update(['status' => 'completed', 'completed_at' => now()]);
        }

        return response()->json(['message' => 'Stop completed.']);
    }

    public function failPooledStop(Request $request, int $stopId)
    {
        $driver = $this->getDriver($request);
        $stop   = PooledStop::with('route')->findOrFail($stopId);

        if ($stop->route->driver_id !== $driver->id) {
            abort(403, 'This stop is not assigned to you.');
        }

        $request->validate(['reason' => 'required|string|max:500']);

        $stop->update(['status' => 'failed', 'actual_arrival' => now(), 'notes' => $request->reason]);

        if ($stop->isDropoff() && $stop->order_id) {
            $order = DeliveryOrder::withoutGlobalScope(MerchantScope::class)->find($stop->order_id);
            if ($order) {
                $this->orderService->transition($order, 'failed', $request->user(), ['reason' => $request->reason]);
            }
        }

        return response()->json(['message' => 'Stop marked as failed.']);
    }

    public function history(Request $request)
    {
        $driver = $this->getDriver($request);

        $orders = DeliveryOrder::where('driver_id', $driver->id)
            ->whereIn('status', ['delivered', 'failed'])
            ->when($request->from, fn($q, $from) => $q->where('delivered_at', '>=', $from))
            ->orderByDesc('delivered_at')
            ->paginate(20);

        return response()->json($orders);
    }

    private function getDriver(Request $request): Driver
    {
        $driver = Driver::where('user_id', $request->user()->id)->first();
        if (!$driver) abort(404, 'Driver profile not found.');
        return $driver;
    }

    private function authorizeStop(RouteStop $stop, Driver $driver): void
    {
        $assignment = $stop->assignment;
        if ($assignment->driver_id !== $driver->id) {
            abort(403, 'This stop is not assigned to you.');
        }
    }
}
