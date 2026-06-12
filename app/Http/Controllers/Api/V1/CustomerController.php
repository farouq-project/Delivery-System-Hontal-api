<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Geocoding\GoogleGeocodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function __construct(private GoogleGeocodingService $geocoder) {}

    public function index(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $query = Customer::where('merchant_id', $merchantId)
            ->when($request->search, fn($q, $s) => $q->where(function($q) use ($s) {
                $q->where('customer_name', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            }))
            ->when($request->vip_level, fn($q, $v) => $q->where('vip_level', $v))
            ->when($request->active !== null, fn($q) => $q->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('customer_name');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name'      => 'required|string|max:255',
            'phone'              => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:255',
            'default_address'    => 'required|string',
            'default_latitude'   => 'nullable|numeric|between:-90,90',
            'default_longitude'  => 'nullable|numeric|between:-180,180',
            'vip_level'          => 'nullable|in:standard,silver,gold,platinum',
            'notes'              => 'nullable|string',
        ]);

        // Auto-geocode if no coordinates provided
        if (empty($data['default_latitude']) && !empty($data['default_address'])) {
            $geo = $this->geocoder->geocode($data['default_address']);
            if ($geo) {
                $data['default_latitude']  = $geo['latitude'];
                $data['default_longitude'] = $geo['longitude'];
            }
        }

        $customer = Customer::create([
            ...$data,
            'ulid'        => Str::ulid(),
            'merchant_id' => $request->user()->merchant_id,
            'vip_level'   => $data['vip_level'] ?? 'standard',
        ]);

        return response()->json(['data' => $customer], 201);
    }

    public function show(Request $request, Customer $customer)
    {
        $this->authorizeMerchant($request, $customer->merchant_id);
        return response()->json(['data' => $customer->load(['orders' => fn($q) => $q->latest()->limit(10)])]);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorizeMerchant($request, $customer->merchant_id);

        $data = $request->validate([
            'customer_name'     => 'sometimes|string|max:255',
            'phone'             => 'nullable|string|max:20',
            'email'             => 'nullable|email',
            'default_address'   => 'sometimes|string',
            'default_latitude'  => 'nullable|numeric|between:-90,90',
            'default_longitude' => 'nullable|numeric|between:-180,180',
            'vip_level'         => 'nullable|in:standard,silver,gold,platinum',
            'notes'             => 'nullable|string',
            'is_active'         => 'nullable|boolean',
        ]);

        // Re-geocode if address changed and no new coords provided
        if (isset($data['default_address']) && empty($data['default_latitude'])) {
            $geo = $this->geocoder->geocode($data['default_address']);
            if ($geo) {
                $data['default_latitude']  = $geo['latitude'];
                $data['default_longitude'] = $geo['longitude'];
            }
        }

        $customer->update($data);
        return response()->json(['data' => $customer->fresh()]);
    }

    public function destroy(Request $request, Customer $customer)
    {
        $this->authorizeMerchant($request, $customer->merchant_id);
        $customer->delete();
        return response()->json(null, 204);
    }

    public function search(Request $request)
    {
        $request->validate(['q' => 'required|string|min:1']);

        $results = Customer::where('merchant_id', $request->user()->merchant_id)
            ->where('is_active', true)
            ->where(function ($q) use ($request) {
                $q->where('customer_name', 'like', "%{$request->q}%")
                  ->orWhere('phone', 'like', "%{$request->q}%");
            })
            ->select(['id', 'ulid', 'customer_name', 'phone', 'default_address', 'default_latitude', 'default_longitude', 'vip_level'])
            ->orderBy('customer_name')
            ->limit($request->limit ?? 10)
            ->get();

        return response()->json(['data' => $results]);
    }

    private function authorizeMerchant(Request $request, int $merchantId): void
    {
        if ($request->user()->merchant_id !== $merchantId && !$request->user()->isSuperAdmin()) {
            abort(403, 'Access denied.');
        }
    }
}
