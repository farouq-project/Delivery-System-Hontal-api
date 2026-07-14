<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Scopes\MerchantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a full Customer Domain rebuild from the source of truth (delivery_orders).
 *
 * This service does NOT contain business logic. It delegates every calculation
 * to the existing services (CustomerProfileService → CustomerHealthService →
 * CustomerSegmentationService) and only handles coordination, dry-run isolation,
 * and change-diff reporting.
 *
 * Idempotent: safe to run multiple times. Produces the same result each run.
 */
class CustomerDomainRebuildService
{
    public function __construct(
        private readonly CustomerProfileService $profileService,
    ) {}

    /**
     * Rebuild the Customer Domain for a single customer.
     *
     * Returns a diff array with keys:
     *   updated          bool   — true if any value changed
     *   profile_rebuilt  bool   — true (always recalculated)
     *   health_updated   bool   — true if health_status changed
     *   segment_updated  bool   — true if segment changed
     *   before           array  — snapshot of key values before rebuild
     *   after            array  — snapshot of key values after rebuild
     *   error            string — non-null when an exception occurred
     */
    public function rebuild(Customer $customer, bool $dryRun = false): array
    {
        $profile = CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->first();

        $before = $this->snapshot($profile);

        try {
            if ($dryRun) {
                // Run full recalculation inside a transaction, then roll it back.
                // All business logic executes normally — only DB writes are undone.
                DB::beginTransaction();
                $fresh = $this->profileService->recalculate($customer);
                $after = $this->snapshot($fresh);
                DB::rollBack();
            } else {
                $fresh = $this->profileService->recalculate($customer);
                $after = $this->snapshot($fresh);
            }
        } catch (\Throwable $e) {
            if ($dryRun && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('[CustomerDomainRebuild] Failed for customer', [
                'customer_id' => $customer->id,
                'merchant_id' => $customer->merchant_id,
                'error'       => $e->getMessage(),
            ]);

            return [
                'updated'         => false,
                'profile_rebuilt' => false,
                'health_updated'  => false,
                'segment_updated' => false,
                'before'          => $before,
                'after'           => $before,
                'error'           => $e->getMessage(),
            ];
        }

        $healthUpdated  = ($before['health_status'] ?? null) !== ($after['health_status'] ?? null);
        $segmentUpdated = ($before['segment'] ?? null)       !== ($after['segment'] ?? null);
        $updated        = $before !== $after;

        return [
            'updated'         => $updated,
            'profile_rebuilt' => true,
            'health_updated'  => $healthUpdated,
            'segment_updated' => $segmentUpdated,
            'before'          => $before,
            'after'           => $after,
            'error'           => null,
        ];
    }

    private function snapshot(?CustomerProfile $profile): array
    {
        if (!$profile) {
            return [
                'total_orders'    => null,
                'total_spending'  => null,
                'last_order_at'   => null,
                'health_status'   => null,
                'segment'         => null,
            ];
        }

        return [
            'total_orders'   => $profile->total_orders,
            'total_spending' => $profile->total_spending,
            'last_order_at'  => $profile->last_order_at?->toDateTimeString(),
            'health_status'  => $profile->health_status,
            'segment'        => $profile->segment,
        ];
    }
}
