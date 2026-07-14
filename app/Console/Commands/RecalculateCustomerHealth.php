<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\MerchantFeature;
use App\Services\CustomerHealthService;
use App\Services\CustomerSegmentationService;
use Illuminate\Console\Command;

class RecalculateCustomerHealth extends Command
{
    protected $signature   = 'customers:recalculate-health {--merchant= : Limit to a specific merchant ID}';
    protected $description = 'Recalculate health status and segment for all customers with active customer_domain feature';

    public function __construct(
        private readonly CustomerHealthService        $healthService,
        private readonly CustomerSegmentationService  $segmentService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $merchantQuery = MerchantFeature::where('feature', 'customer_domain')
            ->where('is_enabled', true);

        if ($merchantId = $this->option('merchant')) {
            $merchantQuery->where('merchant_id', $merchantId);
        }

        $merchantIds = $merchantQuery->pluck('merchant_id');

        if ($merchantIds->isEmpty()) {
            $this->info('No merchants with customer_domain feature enabled.');
            return self::SUCCESS;
        }

        $this->info("Processing {$merchantIds->count()} merchant(s)...");

        $total   = 0;
        $updated = 0;

        foreach ($merchantIds as $mid) {
            Customer::where('merchant_id', $mid)
                ->whereNull('deleted_at')
                ->with('profile')
                ->chunkById(200, function ($customers) use (&$total, &$updated) {
                    foreach ($customers as $customer) {
                        $profile = $customer->profile;

                        if (!$profile) {
                            continue;
                        }

                        $total++;
                        $oldHealth  = $profile->health_status;
                        $oldSegment = $profile->segment;

                        $this->healthService->updateHealth($customer, $profile);
                        $profile->refresh();
                        $this->segmentService->updateSegment($customer, $profile);

                        if ($profile->health_status !== $oldHealth || $profile->segment !== $oldSegment) {
                            $updated++;
                        }
                    }
                });
        }

        $this->info("Done. Checked {$total} customers, updated {$updated}.");

        return self::SUCCESS;
    }
}
