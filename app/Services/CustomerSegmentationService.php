<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProfile;

class CustomerSegmentationService
{
    /**
     * Assign a segment based on VIP level, spending, order count, and health.
     *
     * vip        — VIP level gold or platinum
     * high_value — total spending ≥ Rp 5,000,000
     * returning  — 2+ orders, not dormant/lost
     * new        — 0–1 orders
     * dormant    — health status is dormant or lost
     */
    public function calculate(Customer $customer, CustomerProfile $profile): string
    {
        if (in_array($customer->vip_level, ['gold', 'platinum'])) {
            return 'vip';
        }

        if (in_array($profile->health_status, ['dormant', 'lost'])) {
            return 'dormant';
        }

        if ($profile->total_spending >= 5_000_000) {
            return 'high_value';
        }

        if ($profile->total_orders >= 2) {
            return 'returning';
        }

        return 'new';
    }

    public function updateSegment(Customer $customer, CustomerProfile $profile): void
    {
        $newSegment = $this->calculate($customer, $profile);
        $oldSegment = $profile->segment;

        $profile->update([
            'segment'               => $newSegment,
            'last_segment_check_at' => now(),
        ]);

        if ($oldSegment !== $newSegment) {
            BusinessLogger::segmentChanged($customer->merchant_id, $customer->id, $oldSegment, $newSegment);
        }
    }
}
