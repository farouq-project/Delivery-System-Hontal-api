<?php

namespace App\Listeners;

use App\Events\CustomerCreated;
use App\Services\CustomerProfileService;
use App\Services\FeatureManager;

class InitializeCustomerProfile
{
    public function __construct(
        private readonly CustomerProfileService $profileService,
        private readonly FeatureManager         $featureManager,
    ) {}

    public function handle(CustomerCreated $event): void
    {
        $customer = $event->customer;

        if (!$this->featureManager->isEnabled($customer->merchant_id, 'customer_domain')) {
            return;
        }

        try {
            $this->profileService->initializeProfile($customer);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
