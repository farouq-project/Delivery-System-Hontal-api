<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\OrderStatusHistory;
use App\Services\Geocoding\GoogleGeocodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(private GoogleGeocodingService $geocoder) {}

    public function index(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $query = DeliveryOrder::with(['driver:id,driver_name', 'customer:id,customer_name,vip_level'])
            ->where('merchant_id', $merchantId)
            ->when($request->status,    fn($q, $s) => $q->where('status', $s))
            ->when($request->driver_id, fn($q, $d) => $q->where('driver_id', $d))
            ->when($request->date,      fn($q, $d) => $q->where('requested_delivery_date', $d))
            ->when($request->search, fn($q, $s) => $q->where(function($q) use ($s) {
                $q->where('customer_name', 'like', "%{$s}%")
                  ->orWhere('order_number', 'like', "%{$s}%")
                  ->orWhere('delivery_address', 'like', "%{$s}%");
            }))
            ->orderByDesc('order_created_at');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id'              => 'nullable|integer',
            'customer_name'            => 'required|string|max:255',
            'customer_phone'           => 'nullable|string|max:20',
            'product_name'             => 'required|string|max:255',
            'product_notes'            => 'nullable|string',
            'order_value'              => 'nullable|numeric|min:0',
            'delivery_address'         => 'required|string',
            'delivery_latitude'        => 'nullable|numeric|between:-90,90',
            'delivery_longitude'       => 'nullable|numeric|between:-180,180',
            'delivery_notes'           => 'nullable|string',
            'requested_delivery_date'  => 'nullable|date',
            'requested_delivery_start' => 'nullable|date_format:H:i',
            'requested_delivery_end'   => 'nullable|date_format:H:i',
            'driver_id'                => 'nullable|integer|exists:drivers,id',
        ]);

        // Auto-geocode
        if (empty($data['delivery_latitude']) && !empty($data['delivery_address'])) {
            $geo = $this->geocoder->geocode($data['delivery_address']);
            if ($geo) {
                $data['delivery_latitude']  = $geo['latitude'];
                $data['delivery_longitude'] = $geo['longitude'];
            }
        }

        $merchantId = $request->user()->merchant_id;

        // Generate order number
        $today    = now()->format('Ymd');
        $sequence = Cache::increment("order_seq:{$merchantId}:{$today}");
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

        // If driver assigned at creation
        if (!empty($data['driver_id'])) {
            $this->assignDriver($order, $data['driver_id'], $request->user());
        }

        return response()->json(['data' => $order->load(['driver:id,driver_name', 'customer:id,customer_name'])], 201);
    }

    public function show(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);
        return response()->json(['data' => $order->load(['driver', 'customer', 'statusHistory.changedBy', 'proof'])]);
    }

    public function update(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);

        $data = $request->validate([
            'product_name'             => 'sometimes|string|max:255',
            'product_notes'            => 'nullable|string',
            'order_value'              => 'nullable|numeric|min:0',
            'delivery_notes'           => 'nullable|string',
            'requested_delivery_date'  => 'nullable|date',
            'requested_delivery_start' => 'nullable|date_format:H:i',
            'requested_delivery_end'   => 'nullable|date_format:H:i',
            // Address/customer fields only editable when pending
            'delivery_address'    => 'sometimes|string',
            'delivery_latitude'   => 'nullable|numeric|between:-90,90',
            'delivery_longitude'  => 'nullable|numeric|between:-180,180',
            'customer_name'       => 'sometimes|string|max:255',
            'customer_phone'      => 'nullable|string|max:20',
        ]);

        // Lock address fields after assignment
        if (!$order->isPending()) {
            unset($data['delivery_address'], $data['delivery_latitude'], $data['delivery_longitude'],
                  $data['customer_name'], $data['customer_phone']);
        }

        $data['updated_by'] = $request->user()->id;
        $order->update($data);

        return response()->json(['data' => $order->fresh()->load(['driver:id,driver_name', 'customer:id,customer_name'])]);
    }

    public function destroy(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);

        if (!in_array($order->status, ['pending', 'cancelled'])) {
            return response()->json(['message' => 'Only pending or cancelled orders can be deleted.'], 422);
        }

        $order->delete();
        return response()->json(null, 204);
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

    public function updateStatus(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);

        $request->validate([
            'status'    => 'required|in:pending,assigned,in_progress,delivered,failed,cancelled',
            'reason'    => 'nullable|string',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $fromStatus = $order->status;
        $toStatus   = $request->status;

        $updateData = ['status' => $toStatus];
        if ($toStatus === 'delivered') $updateData['delivered_at'] = now();
        if ($toStatus === 'failed')    $updateData['failed_at']    = now();
        if ($toStatus === 'assigned')  $updateData['assigned_at']  = now();

        if ($request->reason) {
            $updateData[$toStatus === 'failed' ? 'failure_reason' : 'cancellation_reason'] = $request->reason;
        }

        $order->update($updateData);

        OrderStatusHistory::create([
            'order_id'        => $order->id,
            'from_status'     => $fromStatus,
            'to_status'       => $toStatus,
            'changed_by'      => $request->user()->id,
            'changed_by_role' => $request->user()->role,
            'notes'           => $request->reason,
            'latitude'        => $request->latitude,
            'longitude'       => $request->longitude,
        ]);

        return response()->json(['data' => $order->fresh()]);
    }

    public function history(Request $request, DeliveryOrder $order)
    {
        $this->authorizeMerchant($request, $order->merchant_id);
        return response()->json(['data' => $order->statusHistory()->with('changedBy:id,name,role')->get()]);
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
        $fromStatus = $order->status;
        $order->update(['driver_id' => $driverId, 'status' => 'assigned', 'assigned_at' => now()]);

        OrderStatusHistory::create([
            'order_id'        => $order->id,
            'from_status'     => $fromStatus,
            'to_status'       => 'assigned',
            'changed_by'      => $user->id,
            'changed_by_role' => $user->role,
            'notes'           => "Assigned to driver #{$driverId}",
        ]);
    }

    private function authorizeMerchant(Request $request, int $merchantId): void
    {
        if ($request->user()->merchant_id !== $merchantId && !$request->user()->isSuperAdmin()) {
            abort(403, 'Access denied.');
        }
    }
}
