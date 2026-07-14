<?php

namespace App\Services;

use App\Analytics\BusinessRuleRegistry;
use App\Models\Customer;
use App\Models\CustomerProfile;

class CustomerSegmentationService
{
    public function calculate(Customer $customer, CustomerProfile $profile): string
    {
        return BusinessRuleRegistry::classifySegment(
            $customer->vip_level ?? '',
            $profile->health_status ?? '',
            (float) ($profile->total_spending ?? 0),
            (int) ($profile->total_orders ?? 0),
        );
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
