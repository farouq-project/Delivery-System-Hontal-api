<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\Customer;
use App\Models\Scopes\MerchantScope;
use App\Services\CustomerProfileService;
use App\Services\CustomerTimelineService;
use App\Services\FeatureManager;

class UpdateCustomerProfileOnOrder
{
    public function __construct(
        private readonly CustomerProfileService  $profileService,
        private readonly CustomerTimelineService $timelineService,
        private readonly FeatureManager          $featureManager,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

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
            if ($event->toStatus === 'delivered') {
                $this->timelineService->record(
                    $customer,
                    'order_delivered',
                    [
                        'order_id'     => $order->id,
                        'order_number' => $order->order_number,
                        'order_value'  => $order->order_value,
                    ],
                    $event->actor,
                    $order->delivered_at,
                );
            } elseif ($event->toStatus === 'failed') {
                $this->timelineService->record(
                    $customer,
                    'order_failed',
                    [
                        'order_id'       => $order->id,
                        'order_number'   => $order->order_number,
                        'failure_reason' => $order->failure_reason,
                    ],
                    $event->actor,
                    $order->failed_at,
                );
            }

            // Recalculate profile on terminal status changes
            if (in_array($event->toStatus, ['delivered', 'failed', 'cancelled'])) {
                $this->profileService->recalculate($customer);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
