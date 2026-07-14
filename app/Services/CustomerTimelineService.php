<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerTimeline;
use App\Models\User;
use Carbon\Carbon;

class CustomerTimelineService
{
    public function record(
        Customer $customer,
        string   $eventType,
        array    $data       = [],
        ?User    $actor      = null,
        ?Carbon  $occurredAt = null,
    ): CustomerTimeline {
        return CustomerTimeline::create([
            'customer_id' => $customer->id,
            'merchant_id' => $customer->merchant_id,
            'event_type'  => $eventType,
            'event_data'  => empty($data) ? null : $data,
            'actor_id'    => $actor?->id,
            'actor_role'  => $actor?->role,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
