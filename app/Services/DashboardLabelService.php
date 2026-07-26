<?php

namespace App\Services;

use App\Models\MerchantSetting;

/**
 * Translates abstract metric keys into merchant-configured display labels.
 *
 * Consuming code (BI controllers, report generators) should call:
 *
 *   $labels = DashboardLabelService::fromSettings($merchantSetting);
 *   $labels->label('delivery_cost');  // → "Delivery Cost / tray"
 *   $labels->label('unit');           // → "tray"
 *
 * This keeps all industry-specific wording out of business logic.
 * New label keys should be added here — never hardcoded at the call site.
 */
class DashboardLabelService
{
    private static array $unitDisplay = [
        'kg'      => 'kg',
        'tray'    => 'Tray',
        'box'     => 'Box',
        'pcs'     => 'pcs',
        'bottle'  => 'Bottle',
        'gallon'  => 'Galon',
        'package' => 'Paket',
        'order'   => 'Order',
        'custom'  => 'Unit',
    ];

    private string $businessUnit;
    private string $currency;

    private function __construct(string $businessUnit, string $currency)
    {
        $this->businessUnit = $businessUnit;
        $this->currency     = $currency;
    }

    public static function fromSettings(MerchantSetting $settings): self
    {
        return new self(
            businessUnit: $settings->business_unit ?? 'order',
            currency:     $settings->currency      ?? 'IDR',
        );
    }

    public static function withDefaults(): self
    {
        return new self('order', 'IDR');
    }

    /**
     * Resolve a label key to a display string.
     *
     * Supported keys:
     *   unit                 → raw unit slug (e.g., "tray")
     *   unit_label           → display form (e.g., "Tray")
     *   currency             → currency code (e.g., "IDR")
     *   delivery_cost        → "Delivery Cost / Tray"
     *   total_delivered      → "Total Tray Delivered"
     *   revenue              → "Revenue"
     *   orders               → "Orders"
     *   avg_order_value      → "Avg Order Value"
     *   orders_per_unit      → "Orders / Tray"
     */
    public function label(string $key): string
    {
        $unitDisplay = self::$unitDisplay[$this->businessUnit] ?? ucfirst($this->businessUnit);

        return match ($key) {
            'unit'              => $this->businessUnit,
            'unit_label'        => $unitDisplay,
            'currency'          => $this->currency,
            'delivery_cost'     => "Delivery Cost / {$unitDisplay}",
            'total_delivered'   => "Total {$unitDisplay} Delivered",
            'revenue'           => 'Revenue',
            'orders'            => 'Orders',
            'avg_order_value'   => 'Avg Order Value',
            'orders_per_unit'   => "Orders / {$unitDisplay}",
            default             => $key,
        };
    }

    /**
     * Return all labels as an associative array for JSON API responses.
     * BI endpoints can include this in their payload so the frontend
     * never needs to hardcode metric display strings.
     */
    public function all(): array
    {
        $keys = [
            'unit', 'unit_label', 'currency',
            'delivery_cost', 'total_delivered',
            'revenue', 'orders', 'avg_order_value', 'orders_per_unit',
        ];

        return collect($keys)->mapWithKeys(fn($k) => [$k => $this->label($k)])->all();
    }
}
