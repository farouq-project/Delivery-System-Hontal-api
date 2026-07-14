<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Scopes\MerchantScope;
use App\Services\CustomerProfileService;
use App\Services\CustomerTimelineService;
use App\Services\FeatureManager;

class DeliveryOrderObserver
{
    public function __construct(
        private readonly CustomerProfileService  $profileService,
        private readonly CustomerTimelineService $timelineService,
        private readonly FeatureManager          $featureManager,
    ) {}

    public function deleted(DeliveryOrder $order): void
    {
        $this->recalculateProfile($order);
    }

    public function restored(DeliveryOrder $order): void
    {
        $this->recalculateProfile($order);
    }

    public function created(DeliveryOrder $order): void
    {
        if (!$order->customer_id) {
            return;
        }

        // Feature check first — uses 5-min cache, avoids unnecessary DB queries
        if (!$this->featureManager->isEnabled($order->merchant_id, 'customer_domain')) {
            return;
        }

        $customer = Customer::withoutGlobalScope(MerchantScope::class)
            ->find($order->customer_id);

        if (!$customer) {
            return;
        }

        try {
            $this->timelineService->record($customer, 'order_created', [
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
                'order_value'  => $order->order_value,
            ]);

            $this->profileService->recalculate($customer);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function recalculateProfile(DeliveryOrder $order): void
    {
        if (!$order->customer_id) {
            return;
        }

        if (!$this->featureManager->isEnabled($order->merchant_id, 'customer_domain')) {
            return;
        }

        $customer = Customer::withoutGlobalScope(MerchantScope::class)
            ->find($order->customer_id);

        if (!$customer) {
            return;
        }

        try {
            $this->profileService->recalculate($customer);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
