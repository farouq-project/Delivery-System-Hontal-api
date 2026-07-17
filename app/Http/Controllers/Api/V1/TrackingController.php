<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\MerchantSetting;
use App\Models\Scopes\MerchantScope;
use Illuminate\Http\JsonResponse;

class TrackingController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $order = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->with(['merchant', 'driver'])
            ->where('ulid', $token)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Tracking information not found.'], 404);
        }

        $settings = MerchantSetting::where('merchant_id', $order->merchant_id)->first();

        if ($settings && ! $settings->public_tracking_enabled) {
            return response()->json(['message' => 'Tracking is not available for this order.'], 404);
        }

        // Check expiry for completed orders
        if ($settings?->tracking_expiry_hours && in_array($order->status, ['delivered', 'failed'])) {
            $completedAt = $order->delivered_at ?? $order->failed_at ?? $order->updated_at;
            if ($completedAt && now()->isAfter($completedAt->addHours($settings->tracking_expiry_hours))) {
                return response()->json(['message' => 'Tracking link has expired.'], 404);
            }
        }

        // Backend uses 'in_transit'; tracking page expects 'in_progress'
        $status = $order->status === 'in_transit' ? 'in_progress' : $order->status;

        // Driver name only visible when merchant has driver_location_visible enabled (or not configured)
        $driverName = null;
        if ($order->driver && ($settings === null || $settings->driver_location_visible)) {
            $driverName = $order->driver->driver_name;
        }

        return response()->json([
            'data' => [
                'order_number'      => $order->order_number,
                'customer_name'     => $order->customer_name,
                'status'            => $status,
                'merchant_name'     => $order->merchant?->company_name,
                'estimated_arrival' => $order->requested_delivery_date?->format('Y-m-d'),
                'driver_name'       => $driverName,
                'notes'             => $order->delivery_notes,
            ],
        ]);
    }
}
