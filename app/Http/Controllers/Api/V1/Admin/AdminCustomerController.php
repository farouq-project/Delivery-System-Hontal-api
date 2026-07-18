<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCustomerController extends Controller
{
    /**
     * Cross-merchant customer search for Super Admin platform intelligence.
     * Bypasses MerchantScope intentionally — this endpoint is super_admin only.
     */
    public function search(Request $request)
    {
        $request->validate([
            'q'           => 'nullable|string|min:2|max:100',
            'city'        => 'nullable|string|max:100',
            'merchant_id' => 'nullable|integer',
            'vip'         => 'nullable|string',
        ]);

        $query = DB::table('customers')
            ->join('merchants', 'customers.merchant_id', '=', 'merchants.id')
            ->leftJoin('customer_profiles', 'customers.id', '=', 'customer_profiles.customer_id')
            ->whereNull('customers.deleted_at')
            ->select([
                'customers.id',
                'customers.ulid',
                'customers.customer_name',
                'customers.phone',
                'customers.merchant_id',
                'merchants.company_name as merchant_name',
                'customers.vip_level',
                'customers.total_orders',
                'customers.last_order_at',
                'customers.default_address',
                'customers.is_active',
                'customer_profiles.health_status',
                'customer_profiles.segment',
                'customer_profiles.total_spending',
            ]);

        if ($request->filled('q')) {
            $like = '%' . $request->q . '%';
            $query->where(function ($q) use ($like) {
                $q->where('customers.customer_name', 'like', $like)
                  ->orWhere('customers.phone', 'like', $like)
                  ->orWhere('customers.default_address', 'like', $like);
            });
        }

        if ($request->filled('city')) {
            $query->where('customers.default_address', 'like', '%' . $request->city . '%');
        }

        if ($request->filled('merchant_id')) {
            $query->where('customers.merchant_id', $request->merchant_id);
        }

        if ($request->filled('vip')) {
            $query->where('customers.vip_level', $request->vip);
        }

        $results = $query
            ->orderBy('customers.total_orders', 'desc')
            ->orderBy('customers.customer_name')
            ->paginate(25);

        return response()->json($results);
    }
}
