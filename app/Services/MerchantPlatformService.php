<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantSetting;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class MerchantPlatformService
{
    private const DEFAULT_WORKING_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    // ─── Business Profile ────────────────────────────────────────────

    public function getProfile(int $merchantId): array
    {
        $merchant = Merchant::findOrFail($merchantId);

        return [
            'company_name'   => $merchant->company_name,
            'slug'           => $merchant->slug,
            'phone'          => $merchant->phone,
            'email'          => $merchant->email,
            'address'        => $merchant->address,
            'timezone'       => $merchant->timezone,
            'logo_path'      => $merchant->logo_path,
            'tax_number'     => $merchant->tax_number,
            'invoice_footer' => $merchant->invoice_footer,
            'brand_color'    => $merchant->brand_color,
        ];
    }

    public function updateProfile(int $merchantId, array $data): array
    {
        $merchant = Merchant::findOrFail($merchantId);

        $allowed = ['company_name', 'phone', 'email', 'address', 'logo_path', 'tax_number', 'invoice_footer', 'brand_color'];
        $merchant->update(array_intersect_key($data, array_flip($allowed)));

        return $this->getProfile($merchantId);
    }

    // ─── Operational Settings ────────────────────────────────────────

    public function getOperational(int $merchantId): array
    {
        $s = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        return [
            'depot_address'               => $s->depot_address,
            'depot_latitude'              => $s->depot_latitude,
            'depot_longitude'             => $s->depot_longitude,
            'routing_algorithm'           => $s->routing_algorithm ?? 'balanced',
            'routing_mode'                => $s->routing_mode ?? 'balanced',
            'max_stops_per_driver'        => $s->max_stops_per_driver ?? 20,
            'klotter_size'                => $s->klotter_size ?? 10,
            'max_delivery_radius_km'      => $s->max_delivery_radius_km,
            'location_validation_radius'  => $s->location_validation_radius ?? 30,
            'auto_dispatch'               => (bool) ($s->auto_dispatch ?? false),
            'auto_geocode_enabled'        => (bool) ($s->auto_geocode_enabled ?? false),
            'hide_driver_logout'          => (bool) ($s->hide_driver_logout ?? false),
            'order_edit_pin'              => $s->order_edit_pin,
            'batch_enforcement'           => (bool) ($s->batch_enforcement ?? true),
            'two_opt_enabled'             => (bool) ($s->two_opt_enabled ?? true),
            'distance_matrix_cache_ttl'   => $s->distance_matrix_cache_ttl,
        ];
    }

    public function updateOperational(int $merchantId, array $data): array
    {
        $s = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        $allowed = [
            'depot_address', 'depot_latitude', 'depot_longitude',
            'routing_algorithm', 'routing_mode',
            'max_stops_per_driver', 'klotter_size',
            'max_delivery_radius_km', 'location_validation_radius',
            'auto_dispatch', 'auto_geocode_enabled',
            'hide_driver_logout', 'order_edit_pin',
            'batch_enforcement', 'two_opt_enabled', 'distance_matrix_cache_ttl',
        ];
        $s->update(array_intersect_key($data, array_flip($allowed)));

        return $this->getOperational($merchantId);
    }

    // ─── Business Hours ──────────────────────────────────────────────

    public function getHours(int $merchantId): array
    {
        $s = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        return [
            'working_hours_start'  => $s->working_hours_start ?? '08:00',
            'working_hours_end'    => $s->working_hours_end   ?? '17:00',
            'working_days'         => $s->working_days ?? self::DEFAULT_WORKING_DAYS,
            'holiday_mode_enabled' => (bool) ($s->holiday_mode_enabled ?? false),
        ];
    }

    public function updateHours(int $merchantId, array $data): array
    {
        $s = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        $allowed = ['working_hours_start', 'working_hours_end', 'working_days', 'holiday_mode_enabled'];
        $s->update(array_intersect_key($data, array_flip($allowed)));

        return $this->getHours($merchantId);
    }

    // ─── Invoice Settings ────────────────────────────────────────────

    public function getInvoice(int $merchantId): array
    {
        $merchant = Merchant::findOrFail($merchantId);
        $s        = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        return [
            'invoice_prefix'      => $s->invoice_prefix ?? 'INV-',
            'invoice_date_format' => $s->invoice_date_format ?? 'DD/MM/YYYY',
            'invoice_footer'      => $merchant->invoice_footer,
        ];
    }

    public function updateInvoice(int $merchantId, array $data): array
    {
        $merchant = Merchant::findOrFail($merchantId);
        $s        = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        if (array_key_exists('invoice_footer', $data)) {
            $merchant->update(['invoice_footer' => $data['invoice_footer']]);
        }

        $settingFields = array_intersect_key($data, array_flip(['invoice_prefix', 'invoice_date_format']));
        if ($settingFields) {
            $s->update($settingFields);
        }

        return $this->getInvoice($merchantId);
    }

    // ─── Tracking Settings ───────────────────────────────────────────

    public function getTracking(int $merchantId): array
    {
        $s = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        return [
            'tracking_expiry_hours'   => $s->tracking_expiry_hours   ?? 48,
            'public_tracking_enabled' => (bool) ($s->public_tracking_enabled ?? true),
            'show_estimated_arrival'  => (bool) ($s->show_estimated_arrival  ?? true),
            'driver_location_visible' => (bool) ($s->driver_location_visible ?? true),
        ];
    }

    public function updateTracking(int $merchantId, array $data): array
    {
        $s = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        $allowed = ['tracking_expiry_hours', 'public_tracking_enabled', 'show_estimated_arrival', 'driver_location_visible'];
        $s->update(array_intersect_key($data, array_flip($allowed)));

        return $this->getTracking($merchantId);
    }

    // ─── Notification Settings ───────────────────────────────────────

    public function getNotifications(int $merchantId): array
    {
        $s = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        return [
            'whatsapp_notifications_enabled' => (bool) ($s->whatsapp_notifications_enabled ?? false),
            'email_notifications_enabled'    => (bool) ($s->email_notifications_enabled    ?? false),
            'push_notifications_enabled'     => (bool) ($s->push_notifications_enabled     ?? false),
        ];
    }

    public function updateNotifications(int $merchantId, array $data): array
    {
        $s = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);

        $allowed = ['whatsapp_notifications_enabled', 'email_notifications_enabled', 'push_notifications_enabled'];
        $s->update(array_intersect_key($data, array_flip($allowed)));

        return $this->getNotifications($merchantId);
    }

    // ─── Payment Methods ─────────────────────────────────────────────

    public function getPaymentMethods(int $merchantId): Collection
    {
        return MerchantPaymentMethod::where('merchant_id', $merchantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function updatePaymentMethod(int $merchantId, int $id, array $data): MerchantPaymentMethod
    {
        $method = MerchantPaymentMethod::where('merchant_id', $merchantId)->findOrFail($id);

        $allowed = ['label', 'is_enabled', 'is_default'];
        $method->update(array_intersect_key($data, array_flip($allowed)));

        // Enforce single default: if this one becomes default, clear others
        if (!empty($data['is_default'])) {
            MerchantPaymentMethod::where('merchant_id', $merchantId)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        return $method->fresh();
    }

    public function reorderPaymentMethods(int $merchantId, array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            MerchantPaymentMethod::where('merchant_id', $merchantId)
                ->where('id', $id)
                ->update(['sort_order' => $position + 1]);
        }
    }

    public function storePaymentMethod(int $merchantId, array $data): MerchantPaymentMethod
    {
        $maxOrder = MerchantPaymentMethod::where('merchant_id', $merchantId)->max('sort_order') ?? 0;

        return MerchantPaymentMethod::create([
            'merchant_id' => $merchantId,
            'method_key'  => $data['method_key'],
            'label'       => $data['label'],
            'is_enabled'  => $data['is_enabled'] ?? true,
            'is_default'  => false,
            'sort_order'  => $maxOrder + 1,
        ]);
    }

    // ─── Branches ────────────────────────────────────────────────────

    public function getBranches(int $merchantId): Collection
    {
        return MerchantBranch::where('merchant_id', $merchantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function storeBranch(int $merchantId, array $data): MerchantBranch
    {
        $maxOrder = MerchantBranch::where('merchant_id', $merchantId)->max('sort_order') ?? 0;

        return MerchantBranch::create([
            'merchant_id'          => $merchantId,
            'name'                 => $data['name'],
            'address'              => $data['address'] ?? null,
            'depot_latitude'       => $data['depot_latitude'] ?? null,
            'depot_longitude'      => $data['depot_longitude'] ?? null,
            'working_hours_start'  => $data['working_hours_start'] ?? null,
            'working_hours_end'    => $data['working_hours_end'] ?? null,
            'working_days'         => $data['working_days'] ?? null,
            'max_stops_per_driver' => $data['max_stops_per_driver'] ?? null,
            'is_active'            => $data['is_active'] ?? true,
            'sort_order'           => $maxOrder + 1,
        ]);
    }

    public function updateBranch(int $merchantId, int $id, array $data): MerchantBranch
    {
        $branch = MerchantBranch::where('merchant_id', $merchantId)->findOrFail($id);

        $allowed = [
            'name', 'address', 'depot_latitude', 'depot_longitude',
            'working_hours_start', 'working_hours_end', 'working_days',
            'max_stops_per_driver', 'is_active',
        ];
        $branch->update(array_intersect_key($data, array_flip($allowed)));

        return $branch->fresh();
    }

    public function destroyBranch(int $merchantId, int $id): void
    {
        $branch = MerchantBranch::where('merchant_id', $merchantId)->findOrFail($id);
        $branch->delete();
    }
}
