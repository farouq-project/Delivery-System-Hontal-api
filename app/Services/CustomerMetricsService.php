<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProfile;

class CustomerMetricsService
{
    public function getMetrics(Customer $customer): array
    {
        $profile = CustomerProfile::where('customer_id', $customer->id)->first();

        if (!$profile) {
            return $this->emptyMetrics();
        }

        return [
            'total_spending'          => (float) $profile->total_spending,
            'avg_order_value'         => (float) $profile->avg_order_value,
            'avg_delivery_time_hours' => $profile->avg_delivery_time_hours,
            'total_deliveries'        => $profile->total_deliveries,
            'total_orders'            => $profile->total_orders,
            'total_failed'            => $profile->total_failed,
            'success_rate'            => $profile->total_orders > 0
                ? round($profile->total_deliveries / $profile->total_orders * 100, 1)
                : null,
            'first_order_at'          => $profile->first_order_at?->toISOString(),
            'last_order_at'           => $profile->last_order_at?->toISOString(),
            'preferred_payment'       => $profile->preferred_payment,
            'preferred_delivery_time' => $profile->preferred_delivery_time,
            'health_status'           => $profile->health_status,
            'segment'                 => $profile->segment,
        ];
    }

    private function emptyMetrics(): array
    {
        return [
            'total_spending'          => 0.0,
            'avg_order_value'         => 0.0,
            'avg_delivery_time_hours' => null,
            'total_deliveries'        => 0,
            'total_orders'            => 0,
            'total_failed'            => 0,
            'success_rate'            => null,
            'first_order_at'          => null,
            'last_order_at'           => null,
            'preferred_payment'       => null,
            'preferred_delivery_time' => null,
            'health_status'           => 'healthy',
            'segment'                 => 'new',
        ];
    }
}
