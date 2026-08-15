<?php
/**
 * Kirim Test Merchant Onboarding Script
 *
 * Run via tinker:
 *   php artisan tinker --execute="require base_path('scripts/onboard_kirim_merchants.php');"
 *
 * What it does:
 *   1. Creates two new test merchants: TelurKu and PT Test Dulu Aja
 *   2. Enables hontal_kirim feature for Ombar Distribusi (id=22) + the two new merchants
 *   3. Creates merchant_owner + merchant_ops users for all three
 *   4. Tops up an initial 500,000 IDR credit balance for each
 */

use App\Models\HontalKirimCredit;
use App\Models\HontalKirimCreditTransaction;
use App\Models\Merchant;
use App\Models\MerchantFeature;
use App\Models\MerchantSetting;
use App\Models\User;
use App\Models\VipConfig;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$adminUser = User::where('role', 'super_admin')->first();

// ── Helper: enable Kirim for a merchant and top up ───────────────────────────
function enableKirim(Merchant $merchant, User $adminUser, int $topupIdr = 500_000): void
{
    MerchantFeature::updateOrCreate(
        ['merchant_id' => $merchant->id, 'feature' => 'hontal_kirim'],
        ['is_enabled' => true]
    );

    $credit = HontalKirimCredit::firstOrCreate(
        ['merchant_id' => $merchant->id],
        ['balance_idr' => 0, 'low_balance_threshold_idr' => 100_000]
    );

    $credit->increment('balance_idr', $topupIdr);

    HontalKirimCreditTransaction::create([
        'merchant_id' => $merchant->id,
        'type'        => 'topup',
        'amount_idr'  => $topupIdr,
        'note'        => 'Initial Kirim onboarding top-up',
        'created_by'  => $adminUser->id,
    ]);

    echo "  ✓ Kirim enabled for {$merchant->company_name} — balance: Rp " . number_format($credit->fresh()->balance_idr) . "\n";
}

// ── Helper: create a merchant with all prerequisites ─────────────────────────
function createMerchant(string $name, string $slug, string $email, string $address): Merchant
{
    $merchant = Merchant::firstOrCreate(
        ['slug' => $slug],
        [
            'ulid'          => Str::ulid(),
            'company_name'  => $name,
            'address'       => $address,
            'phone'         => '022-0000000',
            'email'         => $email,
            'timezone'      => 'Asia/Jakarta',
            'merchant_type' => 'kirim',
        ]
    );

    // Ensure merchant_type is set even for previously created records
    if ($merchant->merchant_type !== 'kirim') {
        $merchant->update(['merchant_type' => 'kirim']);
    }

    MerchantSetting::firstOrCreate(
        ['merchant_id' => $merchant->id],
        [
            'depot_address'        => $address,
            'depot_latitude'       => -6.9344,
            'depot_longitude'      => 107.6278,
            'max_stops_per_driver' => 30,
            'working_hours_start'  => '07:00:00',
            'working_hours_end'    => '17:00:00',
            'routing_algorithm'    => 'balanced',
            'routing_mode'         => 'balanced',
        ]
    );

    foreach (['standard' => 0, 'silver' => 50, 'gold' => 100, 'platinum' => 200] as $level => $score) {
        VipConfig::firstOrCreate(
            ['merchant_id' => $merchant->id, 'vip_level' => $level],
            ['score_value' => $score]
        );
    }

    echo "  ✓ Merchant created: {$name} (id={$merchant->id})\n";
    return $merchant;
}

// ── Helper: create owner + ops users ─────────────────────────────────────────
function createUsers(Merchant $merchant, string $slug): void
{
    $ownerEmail = "owner@{$slug}.test";
    $opsEmail   = "ops@{$slug}.test";

    User::firstOrCreate(
        ['email' => $ownerEmail],
        [
            'ulid'        => Str::ulid(),
            'merchant_id' => $merchant->id,
            'name'        => "Owner — {$merchant->company_name}",
            'password'    => Hash::make('password'),
            'role'        => 'merchant_owner',
            'is_active'   => true,
        ]
    );

    User::firstOrCreate(
        ['email' => $opsEmail],
        [
            'ulid'        => Str::ulid(),
            'merchant_id' => $merchant->id,
            'name'        => "Ops — {$merchant->company_name}",
            'password'    => Hash::make('password'),
            'role'        => 'merchant_ops',
            'is_active'   => true,
        ]
    );

    echo "  ✓ Users: {$ownerEmail} / {$opsEmail} (password: password)\n";
}

// ═══════════════════════════════════════════════════════════════
echo "\n=== Hontal Kirim Merchant Onboarding ===\n\n";

// 1. TelurKu
echo "1. TelurKu\n";
$telurku = createMerchant('TelurKu', 'telurku', 'admin@telurku.test', 'Jl. Pasteur No. 45, Bandung');
createUsers($telurku, 'telurku');
enableKirim($telurku, $adminUser);

echo "\n";

// 2. PT Test Dulu Aja
echo "2. PT Test Dulu Aja\n";
$pttest = createMerchant('PT Test Dulu Aja', 'pt-test-dulu-aja', 'admin@pttest.test', 'Jl. Dago No. 12, Bandung');
createUsers($pttest, 'pttest');
enableKirim($pttest, $adminUser);

echo "\n";

// 3. Ombar Distribusi (already exists with id=22)
echo "3. Ombar Distribusi (existing, id=22)\n";
$ombar = Merchant::find(22);
if ($ombar) {
    // Create ops user for Ombar if not already there
    User::firstOrCreate(
        ['email' => 'ops@ombar.test'],
        [
            'ulid'        => Str::ulid(),
            'merchant_id' => $ombar->id,
            'name'        => 'Ops — Ombar Distribusi',
            'password'    => Hash::make('password'),
            'role'        => 'merchant_ops',
            'is_active'   => true,
        ]
    );
    $ombar->update(['merchant_type' => 'kirim']);
    echo "  ✓ merchant_type set to 'kirim'\n";
    echo "  ✓ User: ops@ombar.test (password: password)\n";
    enableKirim($ombar, $adminUser, 1_000_000); // larger top-up for existing merchant
} else {
    echo "  ✗ Ombar (id=22) not found — skipping\n";
}

echo "\n=== Done ===\n";
echo "Test logins (all password: 'password'):\n";
echo "  TelurKu owner:        owner@telurku.test\n";
echo "  TelurKu ops:          ops@telurku.test\n";
echo "  PT Test Dulu Aja own: owner@pttest.test\n";
echo "  PT Test Dulu Aja ops: ops@pttest.test\n";
echo "  Ombar ops:            ops@ombar.test\n\n";
