<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\DeliveryOrder;
use App\Models\MerchantSetting;
use App\Models\Scopes\MerchantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TrackingController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $order = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->with(['merchant', 'driver', 'depot'])
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

        // Driver name + location (only when merchant has driver_location_visible enabled)
        $driverName = null;
        $driverLat  = null;
        $driverLng  = null;
        if ($order->driver && ($settings === null || $settings->driver_location_visible)) {
            $driverName = $order->driver->driver_name;
            $driverLat  = $order->driver->current_lat;
            $driverLng  = $order->driver->current_lng;
        }

        // Depot info — only for Kirim orders; used by the tracking map to show the pickup origin
        $depotName = null;
        $depotLat  = null;
        $depotLng  = null;
        if ($order->delivery_type === 'hontal_kirim' && $order->depot) {
            $depotName = $order->depot->name;
            $depotLat  = $order->depot->latitude;
            $depotLng  = $order->depot->longitude;
        }

        // ── Part 6: Predicted delivery time ──────────────────────────────────
        [$predictedAt, $predictionSource] = $this->computePrediction($order);

        $timezone = $order->merchant?->timezone ?? 'Asia/Jakarta';

        return response()->json([
            'data' => [
                'order_number'            => $order->order_number,
                'customer_name'           => $order->customer_name,
                'status'                  => $status,
                'merchant_name'           => $order->merchant?->company_name,
                'estimated_arrival'       => $order->requested_delivery_date?->format('Y-m-d'),
                'driver_name'             => $driverName,
                'notes'                   => $order->delivery_notes,
                'predicted_delivery_time' => $predictedAt
                    ? $predictedAt->setTimezone($timezone)->toIso8601String()
                    : null,
                'prediction_source'       => $predictionSource,
                // Map coordinates
                'delivery_latitude'       => $order->delivery_latitude,
                'delivery_longitude'      => $order->delivery_longitude,
                'driver_lat'              => $driverLat,
                'driver_lng'              => $driverLng,
                // Kirim-specific: pickup depot origin
                'is_kirim'                => $order->delivery_type === 'hontal_kirim',
                'depot_name'              => $depotName,
                'depot_latitude'          => $depotLat,
                'depot_longitude'         => $depotLng,
            ],
        ]);
    }

    private function computePrediction(DeliveryOrder $order): array
    {
        // Already delivered/failed — no prediction needed
        if (in_array($order->status, ['delivered', 'failed', 'cancelled'])) {
            return [null, null];
        }

        $createdAt = $order->order_created_at ?? $order->created_at;
        if (! $createdAt) {
            return [null, null];
        }

        // Try customer-level average first
        if ($order->customer_id) {
            $profile = CustomerProfile::where('customer_id', $order->customer_id)
                ->where('merchant_id', $order->merchant_id)
                ->first();

            if ($profile && $profile->avg_delivery_time_hours) {
                return [
                    (clone $createdAt)->addMinutes((int) round($profile->avg_delivery_time_hours * 60)),
                    'customer_history',
                ];
            }
        }

        // Fallback: merchant-wide average from last 90 days of delivered orders
        $merchantAvgHours = DB::table('delivery_orders')
            ->where('merchant_id', $order->merchant_id)
            ->where('status', 'delivered')
            ->whereNotNull('delivered_at')
            ->whereNotNull('order_created_at')
            ->whereRaw('delivered_at > order_created_at')
            ->where('created_at', '>=', now()->subDays(90))
            ->whereNull('deleted_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, order_created_at, delivered_at) / 60.0) as avg_hours')
            ->value('avg_hours');

        if ($merchantAvgHours) {
            return [
                (clone $createdAt)->addMinutes((int) round($merchantAvgHours * 60)),
                'merchant_average',
            ];
        }

        return [null, null];
    }
}
