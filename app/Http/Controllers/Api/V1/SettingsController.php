<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MerchantSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request)
    {
        $settings = MerchantSetting::where('merchant_id', $request->user()->merchant_id)->first();
        return response()->json(['data' => $settings]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'klotter_size'    => 'sometimes|integer|min:1|max:100',
            'order_edit_pin'  => 'sometimes|string|regex:/^\d{3,6}$/',
            'depot_address'   => 'sometimes|nullable|string|max:500',
            'depot_latitude'  => 'sometimes|nullable|numeric|between:-90,90',
            'depot_longitude' => 'sometimes|nullable|numeric|between:-180,180',
        ]);

        $ownerOnlyFields = ['order_edit_pin', 'depot_address', 'depot_latitude', 'depot_longitude'];
        $isOwner = in_array($request->user()->role, ['merchant_owner', 'super_admin', 'developer']);

        foreach ($ownerOnlyFields as $field) {
            if (isset($data[$field]) && !$isOwner) {
                return response()->json(['message' => 'Only the merchant owner may change this setting.'], 403);
            }
        }

        $settings = MerchantSetting::where('merchant_id', $request->user()->merchant_id)->firstOrFail();
        $settings->update($data);

        return response()->json(['data' => $settings->fresh()]);
    }
}
