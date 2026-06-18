<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\MerchantSetting;
use App\Models\DriverLocation;
use App\Models\OrderStatusHistory;
use App\Models\ProofOfDelivery;
use App\Models\RouteStop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DriverAppController extends Controller
{
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
        $driver = $this->getDriver($request);

        $hideDriverLogout = (bool) MerchantSetting::where('merchant_id', $driver->merchant_id)
            ->value('hide_driver_logout');

        $assignment = $driver->routeAssignments()
            ->whereHas('route', fn($q) => $q->where('route_date', today()))
            ->with(['stops' => function ($q) {
                $q->with('order:id,order_number,customer_name,customer_phone,delivery_address,delivery_latitude,delivery_longitude,delivery_notes,product_name,order_value,status,requested_delivery_start,requested_delivery_end')
                  ->orderByDesc('total_score')
                  ->orderBy('stop_sequence');
            }])
            ->first();

        if (!$assignment) {
            return response()->json(['data' => ['stops' => [], 'total_stops' => 0, 'completed_stops' => 0, 'hide_driver_logout' => $hideDriverLogout]]);
        }

        return response()->json([
            'data' => [
                'hide_driver_logout'  => $hideDriverLogout,
                'route_assignment_id' => $assignment->id,
                'route_date'          => today()->toDateString(),
                'total_stops'         => $assignment->total_stops,
                'completed_stops'     => $assignment->completed_stops,
                'failed_stops'        => $assignment->failed_stops,
                'remaining_stops'     => $assignment->total_stops - $assignment->completed_stops - $assignment->failed_stops,
                'stops'               => $assignment->stops->map(fn($s) => [
                    'stop_id'       => $s->id,
                    'stop_sequence' => $s->stop_sequence,
                    'order_id'      => $s->order_id,
                    'is_locked'     => $s->is_locked,
                    'estimated_arrival' => $s->estimated_arrival?->toISOString(),
                    'order'         => $s->order,
                ]),
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

        // Update driver's live position
        $driver->update([
            'current_lat' => $request->latitude,
            'current_lng' => $request->longitude,
            'last_seen'   => now(),
        ]);

        // Store location history
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
        $stop   = RouteStop::with('order')
            ->findOrFail($stopId);

        $this->authorizeStop($stop, $driver);

        $request->validate([
            'latitude'       => 'nullable|numeric',
            'longitude'      => 'nullable|numeric',
            'recipient_name' => 'nullable|string|max:255',
            'notes'          => 'nullable|string',
            'photo'          => 'nullable|file|max:20480',
        ]);

        $order = $stop->order;

        // Handle photo upload — continue without photo if storage fails
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

        $fromStatus = $order->status;
        $order->update(['status' => 'delivered', 'delivered_at' => now()]);

        // Post-save operations wrapped in try-catch: if any of these fail the
        // delivery is already persisted, so we still return 200 to the driver.
        try {
            OrderStatusHistory::create([
                'order_id'        => $order->id,
                'from_status'     => $fromStatus,
                'to_status'       => 'delivered',
                'changed_by'      => $request->user()->id,
                'changed_by_role' => 'driver',
                'latitude'        => $request->latitude,
                'longitude'       => $request->longitude,
            ]);

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
        $fromStatus = $order->status;

        $order->update([
            'status'         => 'failed',
            'failure_reason' => $request->reason,
            'failed_at'      => now(),
        ]);

        OrderStatusHistory::create([
            'order_id'        => $order->id,
            'from_status'     => $fromStatus,
            'to_status'       => 'failed',
            'changed_by'      => $request->user()->id,
            'changed_by_role' => 'driver',
            'notes'           => $request->reason,
            'latitude'        => $request->latitude,
            'longitude'       => $request->longitude,
        ]);

        $assignment = $stop->assignment;
        $assignment->increment('failed_stops');

        return response()->json(['message' => 'Failure reported.']);
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
