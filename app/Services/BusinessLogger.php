<?php

namespace App\Services;

use App\Models\DeliveryOrder;
use App\Models\Merchant;
use App\Models\Route;
use Illuminate\Support\Facades\Log;

/**
 * Structured business-event logging.
 *
 * All methods write to the 'business' log channel (storage/logs/business.log).
 * Each entry is a structured JSON-serialisable array so log-aggregation tools
 * (Papertrail, Datadog, CloudWatch) can parse and query individual fields.
 *
 * No personal data (customer names, phone numbers, addresses) is included in
 * log entries — only IDs and operational metrics.
 */
class BusinessLogger
{
    private static function write(string $event, array $payload): void
    {
        Log::channel('business')->info($event, array_merge([
            'event'      => $event,
            'ts'         => now()->toISOString(),
            'merchant_id'=> $payload['merchant_id'] ?? null,
        ], $payload));
    }

    public static function orderStatusChanged(
        DeliveryOrder $order,
        string        $fromStatus,
        string        $toStatus,
        string        $actorRole,
    ): void {
        self::write('order.status_changed', [
            'merchant_id' => $order->merchant_id,
            'order_id'    => $order->id,
            'order_number'=> $order->order_number,
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
            'actor_role'  => $actorRole,
        ]);
    }

    public static function routeGenerated(
        Route    $route,
        Merchant $merchant,
        string   $actorRole,
        int      $orderCount,
    ): void {
        self::write('route.generated', [
            'merchant_id'  => $merchant->id,
            'route_id'     => $route->id,
            'route_date'   => $route->route_date?->toDateString(),
            'method'       => $route->generation_method,
            'order_count'  => $orderCount,
            'total_dist_m' => $route->total_distance_m,
            'actor_role'   => $actorRole,
        ]);
    }

    public static function deliveryConfirmed(
        int    $orderId,
        int    $merchantId,
        int    $driverId,
        ?float $lat,
        ?float $lng,
    ): void {
        self::write('delivery.confirmed', [
            'merchant_id' => $merchantId,
            'order_id'    => $orderId,
            'driver_id'   => $driverId,
            'has_gps'     => $lat !== null,
        ]);
    }

    public static function deliveryFailed(
        int    $orderId,
        int    $merchantId,
        int    $driverId,
        string $reason,
    ): void {
        self::write('delivery.failed', [
            'merchant_id' => $merchantId,
            'order_id'    => $orderId,
            'driver_id'   => $driverId,
            'reason_len'  => strlen($reason), // length only — no PII
        ]);
    }

    public static function googleApiCall(
        string $apiType,   // 'geocoding' | 'distance_matrix'
        int    $merchantId,
        int    $inputCount, // rows geocoded or matrix size
        bool   $success,
        ?string $context = null,
    ): void {
        self::write('google_api.call', [
            'merchant_id' => $merchantId,
            'api_type'    => $apiType,
            'input_count' => $inputCount,
            'success'     => $success,
            'context'     => $context,
        ]);
    }

    public static function settingsChanged(
        int    $merchantId,
        string $actorRole,
        array  $changedKeys,
    ): void {
        self::write('settings.changed', [
            'merchant_id'  => $merchantId,
            'actor_role'   => $actorRole,
            'changed_keys' => $changedKeys,
        ]);
    }

    public static function featureToggled(
        int    $merchantId,
        string $feature,
        bool   $enabled,
        string $actorRole,
    ): void {
        self::write('feature.toggled', [
            'merchant_id' => $merchantId,
            'feature'     => $feature,
            'enabled'     => $enabled,
            'actor_role'  => $actorRole,
        ]);
    }

    public static function queueJobDispatched(
        string $jobClass,
        int    $merchantId,
        array  $meta = [],
    ): void {
        self::write('queue.job_dispatched', [
            'merchant_id' => $merchantId,
            'job'         => class_basename($jobClass),
            ...$meta,
        ]);
    }
}
