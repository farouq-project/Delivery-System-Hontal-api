<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $drivers = Driver::where('merchant_id', $merchantId)
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->active !== null, fn($q) => $q->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->withCount(['orders as today_total' => fn($q) => $q->whereDate('requested_delivery_date', today())])
            ->withCount(['orders as today_completed' => fn($q) => $q->whereDate('requested_delivery_date', today())->where('status', 'delivered')])
            ->withCount(['orders as today_failed' => fn($q) => $q->whereDate('requested_delivery_date', today())->where('status', 'failed')])
            ->orderBy('driver_name')
            ->get();

        return response()->json(['data' => $drivers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'driver_name'           => 'required|string|max:255',
            'phone'                 => 'required|string|max:20',
            'vehicle_type'          => 'required|in:motorcycle,car,pickup_truck,van,truck',
            'vehicle_plate'         => 'required|string|max:20',
            'vehicle_capacity_kg'   => 'nullable|numeric',
            'notes'                 => 'nullable|string',
            'create_user_account'   => 'nullable|boolean',
            'user_email'            => 'nullable|email|required_if:create_user_account,true',
            'user_password'         => 'nullable|string|min:8|required_if:create_user_account,true',
        ]);

        $userId = null;
        if ($data['create_user_account'] ?? false) {
            $user = User::create([
                'ulid'        => Str::ulid(),
                'merchant_id' => $request->user()->merchant_id,
                'name'        => $data['driver_name'],
                'email'       => $data['user_email'],
                'password'    => Hash::make($data['user_password']),
                'role'        => 'driver',
                'is_active'   => true,
            ]);
            $userId = $user->id;
        }

        $driver = Driver::create([
            'ulid'               => Str::ulid(),
            'merchant_id'        => $request->user()->merchant_id,
            'user_id'            => $userId,
            'driver_name'        => $data['driver_name'],
            'phone'              => $data['phone'],
            'vehicle_type'       => $data['vehicle_type'],
            'vehicle_plate'      => $data['vehicle_plate'],
            'vehicle_capacity_kg' => $data['vehicle_capacity_kg'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'status'             => 'offline',
        ]);

        return response()->json(['data' => $driver], 201);
    }

    public function show(Request $request, Driver $driver)
    {
        $this->authorize($request, $driver);
        $driver->load(['todayAssignment', 'latestLocation']);
        return response()->json(['data' => $driver]);
    }

    public function update(Request $request, Driver $driver)
    {
        $this->authorize($request, $driver);

        $data = $request->validate([
            'driver_name'         => 'sometimes|string|max:255',
            'phone'               => 'sometimes|string|max:20',
            'vehicle_type'        => 'sometimes|in:motorcycle,car,pickup_truck,van,truck',
            'vehicle_plate'       => 'sometimes|string|max:20',
            'vehicle_capacity_kg' => 'nullable|numeric',
            'notes'               => 'nullable|string',
            'is_active'           => 'nullable|boolean',
        ]);

        $driver->update($data);
        return response()->json(['data' => $driver->fresh()]);
    }

    public function destroy(Request $request, Driver $driver)
    {
        $this->authorize($request, $driver);
        $driver->delete();
        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, Driver $driver)
    {
        $this->authorize($request, $driver);
        $request->validate(['status' => 'required|in:available,delivering,offline']);
        $driver->update(['status' => $request->status]);
        return response()->json(['data' => $driver->fresh()]);
    }

    public function live(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $drivers = Driver::where('merchant_id', $merchantId)
            ->whereIn('status', ['available', 'delivering'])
            ->whereNotNull('current_lat')
            ->select(['id', 'driver_name', 'status', 'current_lat', 'current_lng', 'last_seen', 'vehicle_type'])
            ->get()
            ->map(function ($d) {
                return [
                    'driver_id'   => $d->id,
                    'driver_name' => $d->driver_name,
                    'status'      => $d->status,
                    'lat'         => $d->current_lat,
                    'lng'         => $d->current_lng,
                    'last_seen'   => $d->last_seen?->toISOString(),
                    'vehicle_type' => $d->vehicle_type,
                ];
            });

        return response()->json(['data' => $drivers]);
    }

    public function locationHistory(Request $request, Driver $driver)
    {
        $this->authorize($request, $driver);

        $from = $request->from ?? now()->subHours(8)->toISOString();
        $to   = $request->to   ?? now()->toISOString();

        $locations = DriverLocation::where('driver_id', $driver->id)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->select(['latitude', 'longitude', 'recorded_at', 'speed_kmh', 'battery_pct'])
            ->get();

        return response()->json(['data' => $locations]);
    }

    private function authorize(Request $request, Driver $driver): void
    {
        if ($request->user()->merchant_id !== $driver->merchant_id && !$request->user()->isSuperAdmin()) {
            abort(403, 'Access denied.');
        }
    }
}
