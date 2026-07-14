<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProfile;

class CustomerHealthService
{
    /**
     * Classify health based on recency and delivery success rate.
     *
     * healthy  — ordered in last 30 days, success rate > 80%
     * active   — ordered in last 30–60 days or success rate 50–80%
     * at_risk  — ordered in last 60–90 days or success rate < 50%
     * dormant  — ordered 90–180 days ago
     * lost     — no order in 180+ days
     */
    public function calculate(CustomerProfile $profile): string
    {
        if ($profile->total_orders === 0) {
            return 'healthy'; // brand new customer
        }

        $daysSinceLast = $profile->last_order_at
            ? (int) now()->diffInDays($profile->last_order_at, absolute: true)
            : 999;

        $successRate = $profile->total_orders > 0
            ? $profile->total_deliveries / $profile->total_orders
            : 1.0;

        if ($daysSinceLast > 180) return 'lost';
        if ($daysSinceLast > 90)  return 'dormant';
        if ($daysSinceLast > 60 || $successRate < 0.5) return 'at_risk';
        if ($daysSinceLast > 30 || $successRate < 0.8) return 'active';

        return 'healthy';
    }

    public function updateHealth(Customer $customer, CustomerProfile $profile): void
    {
        $newStatus = $this->calculate($profile);
        $oldStatus = $profile->health_status;

        $profile->update([
            'health_status'        => $newStatus,
            'last_health_check_at' => now(),
        ]);

        if ($oldStatus !== $newStatus) {
            BusinessLogger::healthChanged($customer->merchant_id, $customer->id, $oldStatus, $newStatus);
        }
    }
}
