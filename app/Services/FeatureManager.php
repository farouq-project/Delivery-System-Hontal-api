<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantFeature;
use Illuminate\Support\Facades\Cache;

/**
 * Feature flag management for the Hontal Platform SaaS evolution.
 *
 * Future modules (Customer Intelligence, Insights, Growth, Subscription) are
 * gated behind feature flags. Each flag is stored in merchant_features and
 * cached per merchant for 5 minutes to avoid repeated DB lookups.
 *
 * Available feature keys:
 *   'customer_domain'        — Phase 2A: customer profiles, timelines, health, segmentation
 *   'customer_intelligence'  — advanced customer scoring (future)
 *   'insights'               — delivery analytics dashboards
 *   'growth'                 — multi-driver and multi-branch management
 *   'subscription'           — tiered billing and usage metering
 *   'vip_scoring'            — custom VIP scoring weights per merchant
 *   'advanced_routing'       — time-window hard constraints + capacity limits
 *   'whatsapp_notifications' — automated delivery notifications via WhatsApp
 *
 * Usage:
 *   app(FeatureManager::class)->isEnabled($merchant, 'customer_intelligence');
 *   FeatureManager::forMerchant($merchantId)->isEnabled('insights');
 */
class FeatureManager
{
    private ?int $merchantId = null;

    public static function forMerchant(int $merchantId): static
    {
        $instance = app(static::class);
        $instance->merchantId = $merchantId;
        return $instance;
    }

    /**
     * Check if a feature is enabled for the given merchant.
     * When $merchant is null, uses the merchantId set via forMerchant().
     */
    public function isEnabled(Merchant|int $merchant, string $feature): bool
    {
        $id = $merchant instanceof Merchant ? $merchant->id : ($merchant ?? $this->merchantId);

        if (!$id) {
            return false;
        }

        return Cache::remember("feature:{$id}:{$feature}", 300, function () use ($id, $feature) {
            return MerchantFeature::where('merchant_id', $id)
                ->where('feature', $feature)
                ->where('is_enabled', true)
                ->exists();
        });
    }

    /**
     * Enable a feature for a merchant.
     */
    public function enable(Merchant|int $merchant, string $feature, ?array $config = null, string $actorRole = 'super_admin'): MerchantFeature
    {
        $id = $merchant instanceof Merchant ? $merchant->id : $merchant;

        $record = MerchantFeature::updateOrCreate(
            ['merchant_id' => $id, 'feature' => $feature],
            ['is_enabled' => true, 'config' => $config],
        );

        Cache::forget("feature:{$id}:{$feature}");
        BusinessLogger::featureToggled($id, $feature, true, $actorRole);

        return $record;
    }

    /**
     * Disable a feature for a merchant.
     */
    public function disable(Merchant|int $merchant, string $feature, string $actorRole = 'super_admin'): void
    {
        $id = $merchant instanceof Merchant ? $merchant->id : $merchant;

        MerchantFeature::where('merchant_id', $id)
            ->where('feature', $feature)
            ->update(['is_enabled' => false]);

        Cache::forget("feature:{$id}:{$feature}");
        BusinessLogger::featureToggled($id, $feature, false, $actorRole);
    }

    /**
     * Return all features and their enabled state for a merchant.
     */
    public function allForMerchant(int $merchantId): array
    {
        return MerchantFeature::where('merchant_id', $merchantId)
            ->get(['feature', 'is_enabled', 'config'])
            ->keyBy('feature')
            ->map(fn($f) => ['enabled' => $f->is_enabled, 'config' => $f->config])
            ->all();
    }
}
