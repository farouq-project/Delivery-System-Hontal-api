<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingsController extends Controller
{
    private const ALLOWED_KEYS = [
        'default_trial_days'           => ['type' => 'integer', 'min' => 1,  'max' => 365],
        'default_tracking_expiry_hours'=> ['type' => 'integer', 'min' => 1,  'max' => 8760],
        'google_api_warning_threshold' => ['type' => 'integer', 'min' => 0,  'max' => 1000000],
        'maintenance_mode'             => ['type' => 'boolean', 'min' => null, 'max' => null],
    ];

    public function index(): JsonResponse
    {
        $rows = PlatformSetting::whereIn('key', array_keys(self::ALLOWED_KEYS))
            ->get()
            ->map(fn($s) => [
                'key'         => $s->key,
                'value'       => $this->cast($s->value, $s->type),
                'type'        => $s->type,
                'description' => $s->description,
            ])
            ->keyBy('key');

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings'   => 'required|array',
            'settings.*' => 'present',
        ]);

        $updated = [];
        foreach ($data['settings'] as $key => $value) {
            if (! array_key_exists($key, self::ALLOWED_KEYS)) {
                continue;
            }

            $meta   = self::ALLOWED_KEYS[$key];
            $stored = match ($meta['type']) {
                'integer' => (string) max($meta['min'], min($meta['max'], (int) $value)),
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
                default   => (string) $value,
            };

            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $stored]
            );

            $updated[$key] = $this->cast($stored, $meta['type']);
        }

        return response()->json(['message' => 'Settings updated.', 'data' => $updated]);
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default   => $value,
        };
    }
}
