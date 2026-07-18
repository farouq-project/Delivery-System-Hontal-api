<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantApplication;
use App\Models\MerchantCashier;
use App\Models\MerchantCluster;
use App\Models\MerchantFeature;
use App\Models\MerchantSetting;
use App\Models\MerchantSubscription;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Models\VipConfig;
use App\Services\MerchantActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MerchantProvisioningService
{
    /**
     * Provision a full merchant workspace from an approved application.
     * Everything runs inside a single transaction — any failure rolls back cleanly.
     *
     * @return array{merchant: Merchant, user: User, subscription: MerchantSubscription, temp_password: string}
     */
    public function provision(MerchantApplication $application, ?int $approvedBy = null): array
    {
        return DB::transaction(function () use ($application, $approvedBy) {

            // 1. Create Merchant
            $slug     = Str::slug($application->company_name);
            $existing = Merchant::where('slug', 'like', "{$slug}%")->count();
            if ($existing) {
                $slug = $slug . '-' . ($existing + 1);
            }

            $merchant = Merchant::create([
                'ulid'         => (string) Str::ulid(),
                'company_name' => $application->company_name,
                'slug'         => $slug,
                'address'      => $application->city ?? '',
                'phone'        => $application->phone,
                'email'        => $application->email,
                'timezone'     => 'Asia/Jakarta',
            ]);

            // 2. Create default MerchantSetting with V2 routing defaults
            MerchantSetting::create([
                'merchant_id'          => $merchant->id,
                'max_stops_per_driver' => 35,
                'working_hours_start'  => '08:00:00',
                'working_hours_end'    => '17:00:00',
                'routing_algorithm'    => 'balanced',
                'routing_mode'         => 'balanced',
                'batch_enforcement'    => true,
                'two_opt_enabled'      => true,
            ]);

            // 3. Create Owner User with temporary password
            $tempPassword = Str::password(12, symbols: false);
            $user = User::create([
                'ulid'        => (string) Str::ulid(),
                'merchant_id' => $merchant->id,
                'name'        => $application->owner_name,
                'email'       => $application->email,
                'password'    => Hash::make($tempPassword),
                'role'        => 'merchant_owner',
                'is_active'   => true,
            ]);

            // 4. Resolve plan — from application preference, else starter, else first active
            $plan = PlatformPlan::where('slug', $application->selected_plan)
                ->where('is_active', true)
                ->first()
                ?? PlatformPlan::where('slug', 'starter')->where('is_active', true)->first()
                ?? PlatformPlan::where('is_active', true)->orderBy('display_order')->first();

            // 5. Create trial subscription (uses plan trial_days, default 14)
            $trialDays = $plan?->trial_days ?? 14;
            $subscription = MerchantSubscription::create([
                'merchant_id'   => $merchant->id,
                'plan_id'       => $plan?->id,
                'status'        => 'trial',
                'started_at'    => now(),
                'trial_ends_at' => now()->addDays($trialDays),
                'billing_cycle' => 'monthly',
            ]);

            // 6. Enable default feature flags
            $defaultFeatures = [
                'executive_dashboard',
                'business_intelligence',
                'customer_domain',
                'merchant_platform',
            ];
            foreach ($defaultFeatures as $feature) {
                MerchantFeature::create([
                    'merchant_id' => $merchant->id,
                    'feature'     => $feature,
                    'is_enabled'  => true,
                ]);
            }

            // 7. Seed VIP tiers
            foreach (['standard' => 0, 'silver' => 50, 'gold' => 100, 'platinum' => 200] as $level => $score) {
                VipConfig::create([
                    'merchant_id' => $merchant->id,
                    'vip_level'   => $level,
                    'score_value' => $score,
                ]);
            }

            // 8. Create default cashier and cluster so the workspace is immediately usable
            MerchantCashier::create([
                'merchant_id' => $merchant->id,
                'name'        => 'Kasir 1',
                'is_active'   => true,
            ]);
            MerchantCluster::create([
                'merchant_id' => $merchant->id,
                'name'        => 'Area 1',
                'is_active'   => true,
            ]);

            // 9. Mark application as converted
            $application->update([
                'status'      => 'converted',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            MerchantActivityService::log(
                $merchant->id,
                'merchant_provisioned',
                "Workspace provisioned from application #{$application->id}",
                ['application_id' => $application->id, 'plan' => $plan?->name, 'trial_days' => $trialDays],
                $approvedBy
            );

            return [
                'merchant'      => $merchant,
                'user'          => $user,
                'subscription'  => $subscription,
                'plan'          => $plan,
                'temp_password' => $tempPassword,
            ];
        });
    }

    /**
     * Update the status of a merchant's subscription.
     * Used by Module 8 — Trial Management.
     */
    public function updateSubscriptionStatus(Merchant $merchant, string $status): MerchantSubscription
    {
        $subscription = MerchantSubscription::where('merchant_id', $merchant->id)
            ->whereNull('deleted_at')
            ->latest()
            ->firstOrFail();

        $updates = ['status' => $status];

        if ($status === 'trial' && !$subscription->trial_ends_at) {
            $updates['trial_ends_at'] = now()->addDays(14);
        }

        if ($status === 'active' && !$subscription->started_at) {
            $updates['started_at'] = now();
        }

        $subscription->update($updates);
        return $subscription->fresh();
    }
}
