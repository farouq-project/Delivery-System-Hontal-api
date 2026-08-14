<?php

namespace App\Http\Controllers\Api\V1\Kirim;

use App\Http\Controllers\Controller;
use App\Models\Depot;
use App\Models\DeliveryOrder;
use Illuminate\Http\Request;

class DepotController extends Controller
{
    public function index(Request $request)
    {
        $depots = Depot::query()
            ->when(!$request->boolean('include_inactive'), fn($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $depots]);
    }

    public function store(Request $request)
    {
        $this->authorizeOwnerOrOps($request);

        $data = $request->validate([
            'name'          => 'required|string|max:100',
            'address'       => 'required|string',
            'latitude'      => 'required|numeric|between:-90,90',
            'longitude'     => 'required|numeric|between:-180,180',
            'contact_name'  => 'nullable|string|max:100',
            'contact_phone' => 'nullable|string|max:20',
            'sort_order'    => 'nullable|integer|min:0',
        ]);

        $depot = Depot::create([
            ...$data,
            'merchant_id' => $request->user()->merchant_id,
            'is_active'   => true,
        ]);

        return response()->json(['data' => $depot], 201);
    }

    public function show(Depot $depot)
    {
        return response()->json(['data' => $depot]);
    }

    public function update(Request $request, Depot $depot)
    {
        $this->authorizeOwnerOrOps($request);

        $data = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'address'       => 'sometimes|string',
            'latitude'      => 'sometimes|numeric|between:-90,90',
            'longitude'     => 'sometimes|numeric|between:-180,180',
            'contact_name'  => 'nullable|string|max:100',
            'contact_phone' => 'nullable|string|max:20',
            'sort_order'    => 'nullable|integer|min:0',
            'is_active'     => 'sometimes|boolean',
        ]);

        $depot->update($data);

        return response()->json(['data' => $depot]);
    }

    public function destroy(Request $request, Depot $depot)
    {
        $this->authorizeOwnerOrOps($request);

        $hasActiveOrders = DeliveryOrder::where('depot_id', $depot->id)
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled'])
            ->exists();

        if ($hasActiveOrders) {
            return response()->json([
                'message' => 'Cannot remove a depot with active deliveries. Archive it instead.',
            ], 409);
        }

        // Logical delete — hard delete would null-out past order depot references
        $depot->update(['is_active' => false]);

        return response()->json(['message' => 'Depot archived.']);
    }

    private function authorizeOwnerOrOps(Request $request): void
    {
        $role = $request->user()->role;
        if (!in_array($role, ['merchant_owner', 'merchant_ops', 'super_admin', 'developer'])) {
            abort(403, 'Only the merchant owner or ops may manage depots.');
        }
    }
}
