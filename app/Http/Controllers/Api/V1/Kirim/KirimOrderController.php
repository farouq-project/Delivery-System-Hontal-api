<?php

namespace App\Http\Controllers\Api\V1\Kirim;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Depot;
use App\Models\DeliveryOrder;
use App\Models\MerchantFeature;
use App\Models\PlatformSetting;
use App\Models\Scopes\MerchantScope;
use App\Services\KirimBatchService;
use App\Services\KirimCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class KirimOrderController extends Controller
{
    // Bandung city center — used for the Haversine service-area check
    private const BANDUNG_LAT = -6.9175;
    private const BANDUNG_LNG = 107.6191;

    public function __construct(
        private readonly KirimBatchService  $batchService,
        private readonly KirimCreditService $creditService,
    ) {}

    public function index(Request $request)
    {
        $orders = DeliveryOrder::where('delivery_type', 'hontal_kirim')
            ->with(['depot:id,name', 'batch:id,window_start,window_end,status'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->batch_id, fn($q, $b) => $q->where('batch_id', $b))
            ->orderByDesc('order_created_at')
            ->paginate(25);

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $user       = $request->user();
        $merchantId = $user->merchant_id;

        // ── Gate 1: merchant is a Kirim merchant ──────────────────────────────
        $hasKirim = MerchantFeature::where('merchant_id', $merchantId)
            ->where('feature', 'hontal_kirim')
            ->where('is_enabled', true)
            ->exists();

        if (!$hasKirim) {
            return response()->json(['message' => 'Hontal Kirim is not enabled for this merchant.'], 403);
        }

        // ── Gate 2: credit balance is positive ───────────────────────────────
        if (!$this->creditService->canCreateOrder($merchantId)) {
            return response()->json([
                'message' => 'Insufficient Kirim credit. Top up to create new orders.',
                'code'    => 'kirim_balance_zero',
            ], 422);
        }

        // ── Validate input ───────────────────────────────────────────────────
        $data = $request->validate([
            'depot_id'                  => 'required|integer',
            'customer_name'             => 'required|string|max:255',
            'customer_phone'            => 'nullable|string|max:20',
            'customer_id'               => 'nullable|integer',
            'delivery_address'          => 'required|string',
            'delivery_latitude'         => 'required|numeric|between:-90,90',
            'delivery_longitude'        => 'required|numeric|between:-180,180',
            'delivery_notes'            => 'nullable|string',
            'product_name'              => 'required|string',
            'product_notes'             => 'nullable|string',
            'order_value'               => 'nullable|numeric|min:0',
            'payment_method'            => 'nullable|string|max:50',
            'requested_delivery_date'   => 'nullable|date_format:Y-m-d',
        ]);

        // ── Gate 3: depot belongs to this merchant ───────────────────────────
        $depot = Depot::where('id', $data['depot_id'])
            ->where('is_active', true)
            ->first();

        if (!$depot) {
            return response()->json(['message' => 'Depot not found or inactive.'], 422);
        }

        // ── Gate 4: delivery address is within Bandung service area ──────────
        $radiusKm = (float) (PlatformSetting::where('key', 'kirim_service_radius_km')->value('value') ?: 25);
        $distKm   = $this->haversineKm(
            self::BANDUNG_LAT, self::BANDUNG_LNG,
            (float) $data['delivery_latitude'],
            (float) $data['delivery_longitude'],
        );

        if ($distKm > $radiusKm) {
            return response()->json([
                'message' => "Delivery address is outside the Kirim service area ({$radiusKm} km from Bandung center).",
                'code'    => 'kirim_out_of_area',
                'distance_km' => round($distKm, 1),
            ], 422);
        }

        // ── Assign to current open batch ─────────────────────────────────────
        $batch = $this->batchService->getOrOpenBatch();

        // ── Generate order number (KRM prefix distinguishes Kirim orders) ─────
        $today    = now()->format('Ymd');
        $cacheKey = "kirim_order_seq:{$merchantId}:{$today}";

        if (!Cache::has($cacheKey)) {
            $maxOrder = DeliveryOrder::withoutGlobalScope(MerchantScope::class)
                ->where('merchant_id', $merchantId)
                ->where('order_number', 'like', "KRM-{$today}-%")
                ->max('order_number');
            $seed = $maxOrder ? (int) substr($maxOrder, -4) : 0;
            Cache::put($cacheKey, $seed, now()->endOfDay());
        }

        $sequence    = Cache::increment($cacheKey);
        $orderNumber = 'KRM-' . $today . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);

        // ── Create order ─────────────────────────────────────────────────────
        $order = DeliveryOrder::create([
            'ulid'                    => (string) Str::ulid(),
            'order_number'            => $orderNumber,
            'merchant_id'             => $merchantId,
            'delivery_type'           => 'hontal_kirim',
            'depot_id'                => $depot->id,
            'batch_id'                => $batch->id,
            'customer_id'             => $data['customer_id'] ?? null,
            'customer_name'           => $data['customer_name'],
            'customer_phone'          => $data['customer_phone'] ?? null,
            'product_name'            => $data['product_name'],
            'product_notes'           => $data['product_notes'] ?? null,
            'order_value'             => $data['order_value'] ?? 0,
            'delivery_address'        => $data['delivery_address'],
            'delivery_latitude'       => $data['delivery_latitude'],
            'delivery_longitude'      => $data['delivery_longitude'],
            'delivery_notes'          => $data['delivery_notes'] ?? null,
            'payment_method'          => $data['payment_method'] ?? null,
            'requested_delivery_date' => $data['requested_delivery_date'] ?? now()->toDateString(),
            'status'                  => 'pending',
            'order_created_at'        => now(),
            'created_by'              => $user->id,
        ]);

        return response()->json([
            'data' => $order->load(['depot:id,name', 'batch:id,window_start,window_end,status']),
        ], 201);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371;
        $dlat = deg2rad($lat2 - $lat1);
        $dlng = deg2rad($lng2 - $lng1);
        $a    = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
