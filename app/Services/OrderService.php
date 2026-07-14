<?php

namespace App\Services;

use App\Events\OrderStatusChanged;
use App\Models\DeliveryOrder;
use App\Models\OrderStatusHistory;
use App\Models\User;

/**
 * Centralises all order status transitions.
 *
 * Every status change in the system — assignment, unassignment, manual update,
 * driver delivery/failure — routes through this service so that:
 *  1. Timestamps (delivered_at, failed_at, assigned_at) are always applied consistently.
 *  2. OrderStatusHistory is always recorded.
 *  3. The OrderStatusChanged event is always dispatched (enabling listeners for
 *     notifications, analytics, webhooks, etc.).
 *  4. Structured business logging always fires.
 *
 * Route-stop manipulation (creating/removing stops, decrementing sequences)
 * remains in the controllers — that is operational routing logic, not order
 * status logic, and should stay close to the routing context.
 *
 * Context array keys:
 *   'driver_id'   int|null  — set when transitioning to 'assigned' or clearing on 'pending'
 *   'reason'      string    — failure_reason or cancellation_reason
 *   'notes'       string    — free-text history note (falls back to 'reason')
 *   'latitude'    float     — GPS at time of status change (delivery confirmation)
 *   'longitude'   float     — GPS at time of status change (delivery confirmation)
 */
class OrderService
{
    public function transition(
        DeliveryOrder $order,
        string        $toStatus,
        User          $actor,
        array         $context = [],
    ): void {
        $fromStatus = $order->status;

        $updateData = ['status' => $toStatus];

        // Timestamp tracking per status
        match ($toStatus) {
            'delivered' => $updateData['delivered_at'] = now(),
            'failed'    => $updateData['failed_at']    = now(),
            'assigned'  => $updateData['assigned_at']  = now(),
            default     => null,
        };

        // Revert routing fields when un-assigning
        if ($toStatus === 'pending') {
            $updateData['assigned_at']    = null;
            $updateData['route_sequence'] = null;
        }

        // Driver assignment / unassignment
        if (array_key_exists('driver_id', $context)) {
            $updateData['driver_id'] = $context['driver_id'];
        }

        // Reason field (failure or cancellation)
        if (!empty($context['reason'])) {
            $updateData[$toStatus === 'failed' ? 'failure_reason' : 'cancellation_reason'] = $context['reason'];
        }

        $order->update($updateData);

        // Audit trail
        try {
            OrderStatusHistory::create([
                'order_id'        => $order->id,
                'from_status'     => $fromStatus,
                'to_status'       => $toStatus,
                'changed_by'      => $actor->id,
                'changed_by_role' => $actor->role,
                'notes'           => $context['notes'] ?? $context['reason'] ?? null,
                'latitude'        => $context['latitude'] ?? null,
                'longitude'       => $context['longitude'] ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        // Events — fire and let listeners handle downstream effects
        try {
            event(new OrderStatusChanged($order->fresh(), $fromStatus, $toStatus, $actor, $context));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
