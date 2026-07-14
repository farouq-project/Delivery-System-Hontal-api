<?php

namespace App\Services;

use App\Analytics\BusinessRuleRegistry;
use App\Models\Customer;
use App\Models\CustomerProfile;

class CustomerHealthService
{
    public function calculate(CustomerProfile $profile): string
    {
        $daysSinceLast = $profile->last_order_at
            ? (int) now()->diffInDays($profile->last_order_at, absolute: true)
            : 999;

        $successRate = $profile->total_orders > 0
            ? $profile->total_deliveries / $profile->total_orders
            : 1.0;

        return BusinessRuleRegistry::classifyHealth(
            $daysSinceLast,
            $successRate,
            (int) ($profile->total_orders ?? 0)
        );
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
