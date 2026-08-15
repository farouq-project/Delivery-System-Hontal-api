<?php
/**
 * Create retrospective application records for merchants that existed before the
 * applications page was introduced (Kencana Lima and Ombar Distribusi).
 *
 * Run via:
 *   php artisan tinker --execute="require base_path('scripts/create_retrospective_applications.php');"
 */

use App\Models\Merchant;
use App\Models\MerchantApplication;
use App\Models\User;

$admin = User::where('role', 'super_admin')->first();

$entries = [
    ['slug' => 'kencana-lima', 'type' => 'sistem'],
    ['slug' => 'ombar-distribusi', 'type' => 'kirim'],
];

foreach ($entries as $entry) {
    $merchant = Merchant::where('slug', 'like', "%{$entry['slug']}%")->first();

    if (!$merchant) {
        // Try fuzzy match
        $merchant = Merchant::whereRaw('LOWER(slug) LIKE ?', ['%' . strtolower(str_replace('-', '', $entry['slug'])) . '%'])->first();
    }

    if (!$merchant) {
        echo "  ✗ Merchant not found for slug pattern: {$entry['slug']}\n";
        continue;
    }

    $owner = $merchant->users()->where('role', 'merchant_owner')->first();

    $existing = MerchantApplication::where('email', $merchant->email)->first();
    if ($existing) {
        echo "  → Application already exists for {$merchant->company_name} (id={$existing->id})\n";
        continue;
    }

    $application = MerchantApplication::create([
        'company_name'            => $merchant->company_name,
        'owner_name'              => $owner?->name ?? 'Pemilik',
        'email'                   => $merchant->email,
        'phone'                   => $merchant->phone,
        'city'                    => 'Bandung',
        'business_type'           => 'Distributor',
        'branch_count'            => 0,
        'estimated_monthly_deliveries' => 100,
        'selected_plan'           => $merchant->subscription?->plan?->slug,
        'status'                  => 'converted',
        'merchant_type_requested' => $entry['type'],
        'internal_notes'          => "Retrospective record — merchant existed before the application system was introduced.",
        'approved_by'             => $admin?->id,
        'approved_at'             => $merchant->created_at,
    ]);

    echo "  ✓ Created application for {$merchant->company_name} (app id={$application->id}, type={$entry['type']})\n";
}

echo "\nDone.\n";
