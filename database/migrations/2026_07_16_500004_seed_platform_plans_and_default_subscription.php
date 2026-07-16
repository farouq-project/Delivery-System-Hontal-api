<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('platform_plans')->insert([
            [
                'name'          => 'Starter',
                'slug'          => 'starter',
                'description'   => 'Perfect for small distributors just getting started.',
                'monthly_price' => 299000,
                'delivery_limit'=> 500,
                'branch_limit'  => 1,
                'driver_limit'  => 5,
                'features'      => json_encode(['executive_dashboard', 'customer_domain', 'merchant_platform']),
                'is_active'     => true,
                'display_order' => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'name'          => 'Growth',
                'slug'          => 'growth',
                'description'   => 'For growing distributors managing multiple clusters.',
                'monthly_price' => 699000,
                'delivery_limit'=> 2000,
                'branch_limit'  => 3,
                'driver_limit'  => 15,
                'features'      => json_encode(['executive_dashboard', 'customer_domain', 'merchant_platform', 'business_intelligence']),
                'is_active'     => true,
                'display_order' => 2,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'name'          => 'Professional',
                'slug'          => 'professional',
                'description'   => 'Full-featured for established operations with many drivers.',
                'monthly_price' => 1499000,
                'delivery_limit'=> 10000,
                'branch_limit'  => 10,
                'driver_limit'  => 50,
                'features'      => json_encode(['executive_dashboard', 'customer_domain', 'merchant_platform', 'business_intelligence']),
                'is_active'     => true,
                'display_order' => 3,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'name'          => 'Enterprise',
                'slug'          => 'enterprise',
                'description'   => 'Custom pricing for large-scale distribution networks.',
                'monthly_price' => 0,
                'delivery_limit'=> null,
                'branch_limit'  => null,
                'driver_limit'  => null,
                'features'      => json_encode(['executive_dashboard', 'customer_domain', 'merchant_platform', 'business_intelligence']),
                'is_active'     => true,
                'display_order' => 4,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ]);

        // Assign the first (and currently only) merchant to the Growth plan as active.
        // This represents Merchant #1 (Kencana Lima / existing production merchant).
        // Safe to skip if no merchant exists (fresh install will provision via MerchantProvisioningService).
        $merchantId = DB::table('merchants')->orderBy('id')->value('id');
        $planId     = DB::table('platform_plans')->where('slug', 'growth')->value('id');

        if ($merchantId && $planId) {
            DB::table('merchant_subscriptions')->insert([
                'merchant_id'   => $merchantId,
                'plan_id'       => $planId,
                'status'        => 'active',
                'started_at'    => $now,
                'billing_cycle' => 'monthly',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('merchant_subscriptions')->delete();
        DB::table('platform_plans')->delete();
    }
};
