<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ResolvesCurrentMerchant;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\MerchantCashier;
use App\Models\MerchantSetting;
use App\Models\ProductCatalog;
use App\Models\Route;
use App\Models\RouteAssignment;
use App\Models\RouteStop;
use App\Models\Scopes\MerchantScope;
use App\Services\Geocoding\GoogleGeocodingService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    use ResolvesCurrentMerchant;

    public function __construct(
        private readonly GoogleGeocodingService $geocoder,
        private readonly OrderService           $orderService,
    ) {}

    public function index(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $query = DeliveryOrder::with(['driver:id,driver_name', 'customer:id,customer_name,vip_level'])
            ->where('merchant_id', $merchantId)
            ->when($request->status,         fn($q, $s) => $q->where('status', $s))
            ->when($request->driver_id,      fn($q, $d) => $q->where('driver_id', $d))
            ->when($request->date,           fn($q, $d) => $q->where('requested_delivery_date', $d))
            ->when($request->date_from,      fn($q, $d) => $q->where('requested_delivery_date', '>=', $d))
            ->when($request->date_to,        fn($q, $d) => $q->where('requested_delivery_date', '<=', $d))
            ->when($request->payment_method, fn($q, $m) => $q->where('payment_method', $m))
            ->when($request->search, fn($q, $s) => $q->where(function($q) use ($s) {
                $q->where('customer_name', 'like', "%{$s}%")
                  ->orWhere('order_number', 'like', "%{$s}%")
                  ->orWhere('delivery_address', 'like', "%{$s}%");
            }));

        if (in_array($request->status, ['pending', 'assigned'])) {
            $query->orderByRaw('route_sequence IS NULL')
                  ->orderBy('route_sequence')
                  ->orderByDesc('order_created_at');
        } else {
            $query->orderByDesc('order_created_at');
        }

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(Request $request)
    {
        $this->normalizeTimeFields($request);

        $merchantId    = $request->user()->merchant_id;
        $cashierNames  = MerchantCashier::namesForMerchant($merchantId);

        $cashierRule = empty($cashierNames)
            ? 'nullable|string|max:100'
            : ['nullable', Rule::in($cashierNames)];

        $data = $request->validate([
            'customer_id'              => 'nullable|integer',
            'customer_name'            => 'nullable|string|max:255',
            'customer_phone'           => 'nullable|string|max:20',
            'product_name'             => 'nullable|string',
            'product_notes'            => 'nullable|string',
            'items'                    => 'nullable|array|min:1',
            'items.*.name'             => 'required_with:items|string|max:255',
            'items.*.quantity'         => 'nullable|numeric|min:0',
            'items.*.notes'            => 'nullable|string',
            'order_value'              => 'nullable|numeric|min:0',
            'delivery_address'         => 'required|string',
            'delivery_latitude'        => 'nullable|numeric|between:-90,90',
            'delivery_longitude'       => 'nullable|numeric|between:-180,180',
            'delivery_notes'           => 'nullable|string',
            'requested_delivery_date'  => 'nullable|date',
            'requested_delivery_start' => 'nullable|date_format:H:i',
            'requested_delivery_end'   => 'nullable|date_format:H:i',
            'driver_id'                => 'nullable|integer|exists:drivers,id',
            'cashier_name'             => $cashierRule,
            'payment_method'           => 'nullable|in:cash,transfer,qris,bayar_di_toko',
        ]);

        // Fill customer snapshot from selected customer if not provided
        if (!empty($data['customer_id']) && (empty($data['customer_name']) || empty($data['customer_phone']))) {
            $customer = Customer::where('merchant_id', $merchantId)->find($data['customer_id']);
            if ($customer) {
                $data['customer_name']  = $data['customer_name']  ?? $customer->customer_name;
                $data['customer_phone'] = $data['customer_phone'] ?? $customer->phone;
            }
        }

        if (empty($data['customer_name'])) {
            return response()->json(['message' => 'Customer name is required.', 'errors' => ['customer_name' => ['Customer name is required.']]], 422);
        }

        $data['product_name'] = $this->buildProductSummary($data);

        if (empty($data['requested_delivery_start'])) {
            $data['requested_delivery_start'] = now()->format('H:i');
        }

        if (empty($data['delivery_latitude']) && !empty($data['delivery_address'])) {
            $geo = $this->geocoder->geocode($data['delivery_address']);
            if ($geo) {
                $data['delivery_latitude']  = $geo['latitude'];
                $data['delivery_longitude'] = $geo['longitude'];
            }
        }

        $today    = now()->format('Ymd');
        $cacheKey = "order_seq:{$merchantId}:{$today}";

        // Seed from DB on cache miss so a cache:clear never causes duplicate-key collisions
        if (!Cache::has($cacheKey)) {
            $maxOrder = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
                ->where('merchant_id', $merchantId)
                ->where('order_number', 'like', "ORD-{$today}-%")
                ->max('order_number');
            $seed = $maxOrder ? (int) substr($maxOrder, -4) : 0;
            Cache::put($cacheKey, $seed, now()->endOfDay());
        }

        $sequence    = Cache::increment($cacheKey);
        $orderNumber = "ORD-{$today}-" . str_pad($sequence, 4, '0', STR_PAD_LEFT);

        $order = DeliveryOrder::create([
            ...$data,
            'ulid'             => Str::ulid(),
            'order_number'     => $orderNumber,
            'merchant_id'      => $merchantId,
            'status'           => 'pending',
            'order_created_at' => now(),
            'created_by'       => $request->user()->id,
        ]);

        $this->recordProductCatalog($merchantId, $data);

        if (!empty($data['driver_id'])) {
            $this->assignDriver($order, $data['driver_id'], $request->user());
        }

        return response()->json(['data' => $order->load(['driver:id,driver_name', 'customer:id,customer_name'])], 201);
    }

    /**
     * date_format:H:i rejects empty strings — blank time inputs must become null first.
     */
    private function normalizeTimeFields(Request $request): void
    {
        foreach (['requested_delivery_start', 'requested_delivery_end'] as $field) {
            if ($request->has($field) && $request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }
    }

    private function buildProductSummary(array $data): string
    {
        if (!empty($data['items'])) {
            return collect($data['items'])
                ->map(function ($item) {
                    $name = $item['name'];
                    $qty  = $item['quantity'] ?? null;
                    return $qty ? "{$name} x{$qty}" : $name;
                })
                ->implode(', ');
        }

        return $data['product_name'] ?? '';
    }

    private function recordProductCatalog(int $merchantId, array $data): void
    {
        $names = !empty($data['items'])
            ? collect($data['items'])->pluck('name')->filter()->unique()
            : collect([$data['product_name'] ?? null])->filter();

        foreach ($names as $name) {
            $catalog = ProductCatalog::firstOrNew(['merchant_id' => $merchantId, 'name' => $name]);
            $catalog->usage_count = ($catalog->exists ? $catalog->usage_count : 0) + 1;
            $catalog->last_used_at = now();
            $catalog->save();
        }
    }

    public function productSuggestions(Request $request)
    {
        $request->validate(['q' => 'nullable|string']);

        $results = ProductCatalog::where('merchant_id', $request->user()->merchant_id)
            ->when($request->q, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderByDesc('usage_count')
            ->orderByDesc('last_used_at')
            ->limit(8)
            ->pluck('name');

        return response()->json(['data' => $results]);
    }

    public function show(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);
        return response()->json(['data' => $order->load(['driver', 'customer', 'statusHistory.changedBy', 'proof'])]);
    }

    public function update(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);
        $this->normalizeTimeFields($request);

        $cashierNames = MerchantCashier::namesForMerchant($order->merchant_id);
        $cashierRule  = empty($cashierNames)
            ? 'nullable|string|max:100'
            : ['nullable', Rule::in($cashierNames)];

        $data = $request->validate([
            'product_name'             => 'sometimes|string',
            'product_notes'            => 'nullable|string',
            'items'                    => 'nullable|array|min:1',
            'items.*.name'             => 'required_with:items|string|max:255',
            'items.*.quantity'         => 'nullable|numeric|min:0',
            'items.*.notes'            => 'nullable|string',
            'order_value'              => 'nullable|numeric|min:0',
            'delivery_notes'           => 'nullable|string',
            'requested_delivery_date'  => 'nullable|date',
            'requested_delivery_start' => 'nullable|date_format:H:i',
            'requested_delivery_end'   => 'nullable|date_format:H:i',
            'delivery_address'    => 'sometimes|string',
            'delivery_latitude'   => 'nullable|numeric|between:-90,90',
            'delivery_longitude'  => 'nullable|numeric|between:-180,180',
            'customer_name'       => 'sometimes|string|max:255',
            'customer_phone'      => 'nullable|string|max:20',
            'cashier_name'        => $cashierRule,
            'payment_method'      => 'nullable|in:cash,transfer,qris,bayar_di_toko',
        ]);

        if ($order->status === 'in_transit') {
            unset($data['delivery_address'], $data['delivery_latitude'], $data['delivery_longitude'],
                  $data['customer_name'], $data['customer_phone']);
        }

        if (!empty($data['items'])) {
            $data['product_name'] = $this->buildProductSummary($data);
            $this->recordProductCatalog($order->merchant_id, $data);
        }

        $data['updated_by'] = $request->user()->id;
        $order->update($data);

        return response()->json(['data' => $order->fresh()->load(['driver:id,driver_name', 'customer:id,customer_name'])]);
    }

    public function destroy(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);

        $isOwner = in_array($request->user()->role, ['merchant_owner', 'super_admin', 'developer']);

        $allowed = ['pending', 'assigned', 'cancelled'];
        if ($isOwner) $allowed[] = 'delivered';

        if (!in_array($order->status, $allowed)) {
            return response()->json(['message' => 'This order cannot be deleted.'], 422);
        }

        RouteStop::where('order_id', $order->id)->delete();
        $order->delete();

        return response()->json(null, 204);
    }

    public function bulkUnassign(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:delivery_orders,id',
        ]);

        $orders = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('id', $request->order_ids)
            ->whereNotNull('driver_id')
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled'])
            ->get();

        foreach ($orders as $order) {
            $previousDriverId = $order->driver_id;

            $stop = RouteStop::where('order_id', $order->id)->first();
            if ($stop) {
                $route = $stop->route;
                RouteStop::where('route_assignment_id', $stop->route_assignment_id)
                    ->where('stop_sequence', '>', $stop->stop_sequence)
                    ->decrement('stop_sequence');
                $stop->delete();
                $route->decrement('total_stops');
            }

            $this->orderService->transition($order, 'pending', $request->user(), [
                'driver_id' => null,
                'notes'     => "Bulk unassigned from driver #{$previousDriverId}",
            ]);
        }

        return response()->json(['data' => ['unassigned' => $orders->count()]]);
    }

    public function bulkUpdateCashier(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        if (!in_array($request->user()->role, ['merchant_owner', 'super_admin', 'developer'])) {
            return response()->json(['message' => 'Only the merchant owner can bulk-change cashier names.'], 403);
        }

        $cashierNames = MerchantCashier::namesForMerchant($merchantId);
        $cashierRule  = empty($cashierNames)
            ? 'required|string|max:100'
            : ['required', Rule::in($cashierNames)];

        $request->validate([
            'order_ids'    => 'required|array|min:1',
            'order_ids.*'  => 'integer|exists:delivery_orders,id',
            'cashier_name' => $cashierRule,
        ]);

        $updated = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('id', $request->order_ids)
            ->update(['cashier_name' => $request->cashier_name]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    public function bulkDelete(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:delivery_orders,id',
        ]);

        $orderIds = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('id', $request->order_ids)
            ->whereIn('status', ['pending', 'assigned', 'cancelled'])
            ->pluck('id');

        RouteStop::whereIn('order_id', $orderIds)->delete();
        $deleted = DeliveryOrder::whereIn('id', $orderIds)->delete();

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    public function assign(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);
        $request->validate(['driver_id' => 'required|integer|exists:drivers,id']);

        $driver = Driver::where('id', $request->driver_id)
            ->where('merchant_id', $order->merchant_id)
            ->firstOrFail();

        $this->assignDriver($order, $driver->id, $request->user());

        return response()->json(['data' => $order->fresh()->load(['driver:id,driver_name'])]);
    }

    public function unassign(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);

        if (!$order->driver_id) {
            return response()->json(['message' => 'Order has no assigned driver.'], 422);
        }

        $previousDriverId = $order->driver_id;

        $stop = RouteStop::where('order_id', $order->id)->first();
        if ($stop) {
            $route = $stop->route;
            RouteStop::where('route_assignment_id', $stop->route_assignment_id)
                ->where('stop_sequence', '>', $stop->stop_sequence)
                ->decrement('stop_sequence');
            $stop->delete();
            $route->decrement('total_stops');
        }

        $this->orderService->transition($order, 'pending', $request->user(), [
            'driver_id' => null,
            'notes'     => "Unassigned from driver #{$previousDriverId}",
        ]);

        return response()->json(['data' => $order->fresh()->load(['driver:id,driver_name'])]);
    }

    public function bulkAssign(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:delivery_orders,id',
            'driver_id' => 'required|integer|exists:drivers,id',
        ]);

        $driver = Driver::where('id', $request->driver_id)
            ->where('merchant_id', $merchantId)
            ->firstOrFail();

        $orders = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereIn('id', $request->order_ids)
            ->get();

        foreach ($orders as $order) {
            $this->assignDriver($order, $driver->id, $request->user());
        }

        return response()->json(['data' => ['assigned' => $orders->count()]]);
    }

    public function updateStatus(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);

        $request->validate([
            'status'    => 'required|in:pending,assigned,in_progress,delivered,failed,cancelled',
            'reason'    => 'nullable|string',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $this->orderService->transition($order, $request->status, $request->user(), [
            'reason'    => $request->reason,
            'latitude'  => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        return response()->json(['data' => $order->fresh()]);
    }

    public function history(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);
        return response()->json(['data' => $order->statusHistory()->with('changedBy:id,name,role')->get()]);
    }

    public function klotters(Request $request)
    {
        $request->validate(['date' => 'nullable|date']);

        $merchantId  = $request->user()->merchant_id;
        $date        = $request->date ?? now()->format('Y-m-d');
        $klotterSize = MerchantSetting::where('merchant_id', $merchantId)->value('klotter_size') ?? 7;

        $orders = DeliveryOrder::with('driver:id,driver_name')
            ->where('merchant_id', $merchantId)
            ->where('requested_delivery_date', $date)
            ->whereNotNull('driver_id')
            ->orderBy('driver_id')
            ->orderBy('route_sequence')
            ->orderBy('order_created_at')
            ->get();

        $drivers = $orders->groupBy('driver_id')->map(function ($driverOrders) {
            $driver  = $driverOrders->first()->driver;
            $getTime = fn($o) => $o->assigned_at ?? $o->order_created_at;

            $byMinute = $driverOrders
                ->sortBy(fn($o) => $getTime($o)?->timestamp ?? 0)
                ->groupBy(fn($o) => $getTime($o)?->format('H:i') ?? '—');

            $klotterNumber = 1;
            $allKlotters   = [];

            foreach ($byMinute as $klotterOrders) {
                $allKlotters[] = [
                    'klotter_number' => $klotterNumber++,
                    'dispatch_time'  => $getTime($klotterOrders->first())?->toISOString(),
                    'orders'         => $klotterOrders->values(),
                ];
            }

            return [
                'driver_id'    => $driver?->id,
                'driver_name'  => $driver?->driver_name,
                'total_orders' => $driverOrders->count(),
                'klotters'     => $allKlotters,
            ];
        })->values();

        return response()->json([
            'data' => [
                'date'         => $date,
                'klotter_size' => $klotterSize,
                'drivers'      => $drivers,
            ],
        ]);
    }

    public function geocode(Request $request)
    {
        $request->validate(['address' => 'required|string']);
        $result = $this->geocoder->geocode($request->address);

        if (!$result) {
            return response()->json(['message' => 'Address not found'], 422);
        }

        return response()->json(['data' => $result]);
    }

    private function assignDriver(DeliveryOrder $order, int $driverId, $user): void
    {
        $existingStop = RouteStop::where('order_id', $order->id)->first();
        if ($existingStop) {
            $route = $existingStop->route;
            RouteStop::where('route_assignment_id', $existingStop->route_assignment_id)
                ->where('stop_sequence', '>', $existingStop->stop_sequence)
                ->decrement('stop_sequence');
            $existingStop->delete();
            if ($route) {
                $route->decrement('total_stops');
            }
        }

        $this->orderService->transition($order, 'assigned', $user, [
            'driver_id' => $driverId,
            'notes'     => "Assigned to driver #{$driverId}",
        ]);

        try {
            $this->autoAddRouteStop($order, $driverId, $user);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function autoAddRouteStop(DeliveryOrder $order, int $driverId, $user): void
    {
        $driver = Driver::find($driverId);
        if (!$driver) return;

        $routeDate = $order->requested_delivery_date ?? now()->format('Y-m-d');

        $route = Route::firstOrCreate(
            ['merchant_id' => $driver->merchant_id, 'route_date' => $routeDate],
            [
                'ulid'              => Str::ulid(),
                'status'            => 'draft',
                'generation_method' => 'manual',
                'generated_by'      => $user->id,
                'generated_at'      => now(),
            ]
        );

        $assignment = RouteAssignment::firstOrCreate(
            ['route_id' => $route->id, 'driver_id' => $driverId],
            ['sequence_number' => $route->assignments()->count() + 1]
        );

        $nextSeq = (int) RouteStop::where('route_assignment_id', $assignment->id)->max('stop_sequence') + 1;

        RouteStop::create([
            'route_id'            => $route->id,
            'route_assignment_id' => $assignment->id,
            'order_id'            => $order->id,
            'stop_sequence'       => $nextSeq,
            'is_manually_placed'  => true,
        ]);

        $route->increment('total_stops');
    }
}
