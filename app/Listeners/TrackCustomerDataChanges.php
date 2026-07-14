<?php

namespace App\Listeners;

use App\Events\CustomerUpdated;
use App\Services\CustomerTimelineService;
use App\Services\FeatureManager;

class TrackCustomerDataChanges
{
    public function __construct(
        private readonly CustomerTimelineService $timelineService,
        private readonly FeatureManager          $featureManager,
    ) {}

    public function handle(CustomerUpdated $event): void
    {
        $customer = $event->customer;

        if (!$this->featureManager->isEnabled($customer->merchant_id, 'customer_domain')) {
            return;
        }

        try {
            $changes       = $event->changes;
            $addressFields = ['default_address', 'default_latitude', 'default_longitude'];
            $recorded      = [];

            foreach ($changes as $field => $newValue) {
                // Group all address-related changes into one timeline entry
                if (in_array($field, $addressFields)) {
                    if (!in_array('address_changed', $recorded)) {
                        $this->timelineService->record($customer, 'address_changed', [
                            'changed_fields' => array_intersect_key($changes, array_flip($addressFields)),
                        ]);
                        $recorded[] = 'address_changed';
                    }
                    continue;
                }

                $eventType = match ($field) {
                    'phone'     => 'phone_updated',
                    'vip_level' => 'vip_changed',
                    'is_active' => 'status_changed',
                    default     => null,
                };

                if ($eventType && !in_array($eventType, $recorded)) {
                    $this->timelineService->record($customer, $eventType, ['new_value' => $newValue]);
                    $recorded[] = $eventType;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
