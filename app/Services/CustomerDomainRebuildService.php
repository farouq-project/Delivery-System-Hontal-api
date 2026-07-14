<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\CustomerTimeline;
use App\Models\DeliveryOrder;
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
        private readonly CustomerProfileService  $profileService,
        private readonly CustomerTimelineService $timelineService,
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
                $fresh          = $this->profileService->recalculate($customer);
                $after          = $this->snapshot($fresh);
                $timelineAdded  = $this->backfillTimeline($customer);
                DB::rollBack();
            } else {
                $fresh          = $this->profileService->recalculate($customer);
                $after          = $this->snapshot($fresh);
                $timelineAdded  = $this->backfillTimeline($customer);
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
                'timeline_added'  => 0,
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
            'timeline_added'  => $timelineAdded,
            'before'          => $before,
            'after'           => $after,
            'error'           => null,
        ];
    }

    /**
     * Create timeline entries for historical orders that have no entry yet.
     * Idempotent: existing entries (keyed by order_id) are skipped.
     * Returns the number of entries inserted.
     */
    private function backfillTimeline(Customer $customer): int
    {
        $orders = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->whereNull('deleted_at')
            ->orderBy('order_created_at')
            ->get(['id', 'order_number', 'order_value', 'status',
                   'order_created_at', 'delivered_at', 'failed_at', 'failure_reason']);

        if ($orders->isEmpty()) {
            return 0;
        }

        // Build lookup: event_type => [order_id => true] for fast dedup
        $existing = CustomerTimeline::withoutGlobalScope(MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->whereIn('event_type', ['order_created', 'order_delivered', 'order_failed'])
            ->get(['event_type', 'event_data'])
            ->groupBy('event_type')
            ->map(fn($rows) => $rows
                ->map(fn($r) => $r->event_data['order_id'] ?? null)
                ->filter()
                ->flip()
                ->all()
            );

        $created   = $existing['order_created']   ?? [];
        $delivered = $existing['order_delivered']  ?? [];
        $failed    = $existing['order_failed']     ?? [];
        $added = 0;

        foreach ($orders as $order) {
            if (!isset($created[$order->id])) {
                $this->timelineService->record($customer, 'order_created', [
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'order_value'  => $order->order_value,
                ], null, $order->order_created_at);
                $added++;
            }

            if ($order->status === 'delivered' && !isset($delivered[$order->id])) {
                $this->timelineService->record($customer, 'order_delivered', [
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'order_value'  => $order->order_value,
                ], null, $order->delivered_at ?? $order->order_created_at);
                $added++;
            }

            if ($order->status === 'failed' && !isset($failed[$order->id])) {
                $this->timelineService->record($customer, 'order_failed', [
                    'order_id'       => $order->id,
                    'order_number'   => $order->order_number,
                    'failure_reason' => $order->failure_reason,
                ], null, $order->failed_at ?? $order->order_created_at);
                $added++;
            }
        }

        return $added;
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
