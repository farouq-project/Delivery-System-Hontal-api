<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MerchantCashier;
use App\Models\MerchantCluster;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantSetting;
use App\Services\BusinessLogger;
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
            'klotter_size'       => 'sometimes|integer|min:1|max:100',
            'order_edit_pin'     => 'sometimes|string|regex:/^\d{3,6}$/',
            'depot_address'      => 'sometimes|nullable|string|max:500',
            'depot_latitude'     => 'sometimes|nullable|numeric|between:-90,90',
            'depot_longitude'    => 'sometimes|nullable|numeric|between:-180,180',
            'hide_driver_logout' => 'sometimes|boolean',
        ]);

        $ownerOnlyFields = ['order_edit_pin', 'depot_address', 'depot_latitude', 'depot_longitude', 'hide_driver_logout'];
        $isOwner = in_array($request->user()->role, ['owner', 'merchant_owner', 'super_admin', 'developer']);

        foreach ($ownerOnlyFields as $field) {
            if (isset($data[$field]) && !$isOwner) {
                return response()->json(['message' => 'Only the merchant owner may change this setting.'], 403);
            }
        }

        $merchantId = $request->user()->merchant_id;
        $settings   = MerchantSetting::firstOrCreate(['merchant_id' => $merchantId]);
        $settings->update($data);

        BusinessLogger::settingsChanged($merchantId, $request->user()->role, array_keys($data));

        return response()->json(['data' => $settings->fresh()]);
    }

    // ─── Cashier management ────────────────────────────────────────────────────

    public function indexCashiers(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $cashiers = MerchantCashier::where('merchant_id', $merchantId)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return response()->json(['data' => $cashiers]);
    }

    public function storeCashier(Request $request)
    {
        $this->requireOwner($request);

        $merchantId = $request->user()->merchant_id;

        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $cashier = MerchantCashier::create([
            'merchant_id' => $merchantId,
            'name'        => $request->name,
            'is_active'   => true,
        ]);

        return response()->json(['data' => $cashier], 201);
    }

    public function destroyCashier(Request $request, int $id)
    {
        $this->requireOwner($request);

        $cashier = MerchantCashier::where('merchant_id', $request->user()->merchant_id)
            ->findOrFail($id);

        $cashier->delete();

        return response()->json(null, 204);
    }

    // ─── Cluster management ────────────────────────────────────────────────────

    public function indexClusters(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $clusters = MerchantCluster::where('merchant_id', $merchantId)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return response()->json(['data' => $clusters]);
    }

    public function storeCluster(Request $request)
    {
        $this->requireOwner($request);

        $merchantId = $request->user()->merchant_id;

        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $cluster = MerchantCluster::create([
            'merchant_id' => $merchantId,
            'name'        => $request->name,
            'is_active'   => true,
        ]);

        return response()->json(['data' => $cluster], 201);
    }

    public function destroyCluster(Request $request, int $id)
    {
        $this->requireOwner($request);

        $cluster = MerchantCluster::where('merchant_id', $request->user()->merchant_id)
            ->findOrFail($id);

        $cluster->delete();

        return response()->json(null, 204);
    }

    // ─── Payment Methods (read-only for all authenticated roles) ──────────────

    public function indexPaymentMethods(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $methods = MerchantPaymentMethod::where('merchant_id', $merchantId)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'method_key', 'label', 'is_default']);

        return response()->json(['data' => $methods]);
    }

    private function requireOwner(Request $request): void
    {
        if (!in_array($request->user()->role, ['merchant_owner', 'super_admin', 'developer'])) {
            abort(403, 'Only the merchant owner can manage this setting.');
        }
    }
}
