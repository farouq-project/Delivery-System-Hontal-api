<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DeliveryOrder;
use App\Models\Scopes\MerchantScope;

class CustomerProfileService
{
    public function __construct(
        private readonly CustomerTimelineService     $timeline,
        private readonly CustomerHealthService       $health,
        private readonly CustomerSegmentationService $segmentation,
    ) {}

    /**
     * Create an empty profile for a newly-created customer and record the 'created' timeline entry.
     */
    public function initializeProfile(Customer $customer): CustomerProfile
    {
        $profile = CustomerProfile::firstOrCreate(
            ['customer_id' => $customer->id],
            ['merchant_id' => $customer->merchant_id],
        );

        // Record creation only on first init (no previous timeline events)
        if ($profile->wasRecentlyCreated) {
            $this->timeline->record($customer, 'created');
            BusinessLogger::profileUpdated($customer->merchant_id, $customer->id, 'initialized');
        }

        return $profile;
    }

    /**
     * Full recalculation from delivery_orders. Called after every order status change.
     * All stats are derived from DB — no incremental logic, always consistent.
     */
    public function recalculate(Customer $customer): CustomerProfile
    {
        // Bypass MerchantScope so we can query cross-context (queue jobs, observers)
        $orders = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->whereNull('deleted_at')
            ->get([
                'id', 'order_value', 'payment_method', 'status',
                'requested_delivery_start', 'order_created_at', 'delivered_at', 'assigned_at',
            ]);

        $delivered = $orders->where('status', 'delivered');
        $failed    = $orders->where('status', 'failed');

        $totalOrders     = $orders->count();
        $totalDeliveries = $delivered->count();
        $totalFailed     = $failed->count();
        $totalSpending   = (float) $delivered->sum('order_value');
        $avgOrderValue   = $totalDeliveries > 0 ? round($totalSpending / $totalDeliveries, 2) : 0.0;

        $firstOrderAt = $orders->min('order_created_at');
        $lastOrderAt  = $orders->max('order_created_at');

        // Most frequent payment method (from delivered orders only)
        $preferredPayment = $delivered
            ->groupBy('payment_method')
            ->sortByDesc(fn($g) => $g->count())
            ->keys()
            ->filter()
            ->first();

        // Most frequent delivery time window (from delivered orders only)
        $preferredDeliveryTime = $delivered
            ->groupBy('requested_delivery_start')
            ->sortByDesc(fn($g) => $g->count())
            ->filter(fn($g, $k) => !empty($k))
            ->keys()
            ->first();

        // Average delivery time (order_created_at → delivered_at), in hours
        $deliveryTimes = $delivered
            ->filter(fn($o) => $o->order_created_at && $o->delivered_at)
            ->map(fn($o) => abs($o->delivered_at->diffInMinutes($o->order_created_at)) / 60.0);
        $avgDeliveryTime = $deliveryTimes->count() > 0
            ? round((float) $deliveryTimes->avg(), 2)
            : null;

        $profile = CustomerProfile::firstOrCreate(
            ['customer_id' => $customer->id],
            ['merchant_id' => $customer->merchant_id],
        );

        $profile->update([
            'total_orders'            => $totalOrders,
            'total_deliveries'        => $totalDeliveries,
            'total_failed'            => $totalFailed,
            'total_spending'          => $totalSpending,
            'avg_order_value'         => $avgOrderValue,
            'first_order_at'          => $firstOrderAt,
            'last_order_at'           => $lastOrderAt,
            'preferred_payment'       => $preferredPayment,
            'preferred_delivery_time' => $preferredDeliveryTime,
            'avg_delivery_time_hours' => $avgDeliveryTime,
        ]);

        $fresh = $profile->fresh();
        $this->health->updateHealth($customer, $fresh);
        $this->segmentation->updateSegment($customer, $profile->fresh());

        BusinessLogger::profileUpdated($customer->merchant_id, $customer->id, 'recalculate');

        return $profile->fresh();
    }
}
