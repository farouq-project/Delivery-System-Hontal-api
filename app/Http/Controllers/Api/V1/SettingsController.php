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
            'klotter_size' => 'sometimes|integer|min:1|max:100',
        ]);

        $settings = MerchantSetting::where('merchant_id', $request->user()->merchant_id)->firstOrFail();
        $settings->update($data);

        return response()->json(['data' => $settings->fresh()]);
    }
}
