<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ResolvesCurrentMerchant;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\MerchantCluster;
use App\Models\MerchantSetting;
use App\Services\CustomerDomainRebuildService;
use App\Services\Geocoding\GoogleGeocodingService;
use App\Services\GoogleMapsLinkService;
use App\Services\LocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class CustomerController extends Controller
{
    use ResolvesCurrentMerchant;

    public function __construct(
        private GoogleGeocodingService       $geocoder,
        private GoogleMapsLinkService        $mapsLink,
        private CustomerDomainRebuildService $rebuilder,
    ) {}

    // ─── Resolve Google Maps link (unauthenticated from user's perspective but still auth-gated) ─

    public function resolveMapsLink(Request $request)
    {
        $data = $request->validate([
            'url' => 'required|string|max:2000',
        ]);

        if (!$this->mapsLink->isGoogleMapsUrl($data['url'])) {
            return response()->json([
                'message' => 'That does not appear to be a Google Maps URL. Paste a link from maps.google.com or maps.app.goo.gl.',
            ], 422);
        }

        $coords = $this->mapsLink->extractCoordinates($data['url']);

        if (!$coords) {
            return response()->json([
                'message' => 'Could not extract coordinates from that link. Make sure the link points to a specific location, not a search result.',
            ], 422);
        }

        return response()->json(['data' => $coords]);
    }

    // ─── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $allowedSorts = ['customer_name', 'default_latitude', 'default_longitude', 'vip_level', 'total_belanja', 'avg_belanja_per_month'];
        $sortBy  = in_array($request->sort_by, $allowedSorts) ? $request->sort_by : 'customer_name';
        $sortDir = $request->sort_dir === 'desc' ? 'desc' : 'asc';

        $query = Customer::where('merchant_id', $merchantId)
            ->select('customers.*')
            ->selectRaw(
                "(SELECT COALESCE(SUM(order_value),0) FROM delivery_orders
                  WHERE customer_id=customers.id
                  AND status NOT IN ('cancelled','failed')
                  AND deleted_at IS NULL) AS total_belanja"
            )
            ->selectRaw(
                "(SELECT COALESCE(SUM(order_value),0) FROM delivery_orders
                  WHERE customer_id=customers.id
                  AND status NOT IN ('cancelled','failed')
                  AND deleted_at IS NULL)
                 / GREATEST(1, TIMESTAMPDIFF(MONTH, customers.created_at, NOW()) + 1)
                 AS avg_belanja_per_month"
            )
            ->when($request->search, fn($q, $s) => $q->where(function($q) use ($s) {
                $q->where('customer_name', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('default_address', 'like', "%{$s}%")
                  ->orWhereExists(function($sub) use ($s) {
                      $sub->from('delivery_orders')
                          ->whereColumn('customer_id', 'customers.id')
                          ->where('order_number', 'like', "%{$s}%")
                          ->whereNull('deleted_at');
                  })
                  ->orWhereExists(function($sub) use ($s) {
                      $sub->from('customer_tag_assignments')
                          ->join('customer_tags', 'customer_tag_assignments.tag_id', '=', 'customer_tags.id')
                          ->whereColumn('customer_tag_assignments.customer_id', 'customers.id')
                          ->where('customer_tags.name', 'like', "%{$s}%");
                  });
            }))
            ->when($request->vip_level, fn($q, $v) => $q->where('vip_level', $v))
            ->when($request->active !== null, fn($q) => $q->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->has_coords === '1', fn($q) => $q->whereNotNull('default_latitude'))
            ->when($request->has_coords === '0', fn($q) => $q->whereNull('default_latitude'))
            ->when($request->cluster_filter === '1', fn($q) => $q->whereNotNull('cluster')->where('cluster', '!=', 'no cluster'))
            ->when($request->cluster_filter === '0', fn($q) => $q->where('cluster', 'no cluster'))
            ->when($request->health_filter, fn($q, $h) => $q->whereExists(function($sub) use ($h) {
                $sub->from('customer_profiles')
                    ->whereColumn('customer_id', 'customers.id')
                    ->where('health_status', $h);
            }))
            ->when($request->segment_filter, fn($q, $seg) => $q->whereExists(function($sub) use ($seg) {
                $sub->from('customer_profiles')
                    ->whereColumn('customer_id', 'customers.id')
                    ->where('segment', $seg);
            }))
            ->when($request->status_filter === 'active',   fn($q) => $q->where('is_active', true))
            ->when($request->status_filter === 'inactive', fn($q) => $q->where('is_active', false));

        if (in_array($sortBy, ['default_latitude', 'default_longitude'])) {
            $query->orderByRaw("`{$sortBy}` IS NULL ASC")->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    // ─── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name'      => 'required|string|max:255',
            'phone'              => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:255',
            'default_address'    => 'nullable|string',
            'default_latitude'   => 'nullable|numeric|between:-90,90',
            'default_longitude'  => 'nullable|numeric|between:-180,180',
            'location_source'    => 'nullable|in:google_maps_link,manual_pin,address_geocoding,unknown',
            'google_maps_link'   => 'nullable|string|max:2000',
            'vip_level'          => 'nullable|in:standard,silver,gold,platinum',
            'cluster'            => 'nullable|string|max:50',
            'notes'              => 'nullable|string',
        ]);

        $merchantId = $request->user()->merchant_id;

        if (empty($data['cluster'])) {
            $data['cluster'] = $this->detectCluster($merchantId, $data['customer_name']);
        }

        [$data] = $this->resolveLocationSource($data, null, $merchantId);

        $customer = Customer::create([
            ...$data,
            'ulid'        => Str::ulid(),
            'merchant_id' => $merchantId,
            'vip_level'   => $data['vip_level'] ?? 'standard',
        ]);

        $settings = MerchantSetting::where('merchant_id', $merchantId)->first();
        $fresh    = $customer->fresh();

        return response()->json([
            'data'           => $fresh,
            'depot_distance' => $this->depotDistance($fresh, $settings),
            'warnings'       => $this->buildWarnings($fresh, $settings),
        ], 201);
    }

    // ─── Show ──────────────────────────────────────────────────────────────────

    public function show(Request $request, Customer $customer)
    {
        $this->authorizeMerchant($request, $customer->merchant_id);

        $settings = MerchantSetting::where('merchant_id', $customer->merchant_id)->first();

        return response()->json([
            'data'           => $customer->load(['orders' => fn($q) => $q->latest()->limit(10)]),
            'depot_distance' => $this->depotDistance($customer, $settings),
            'location_confidence' => $customer->locationConfidence(),
        ]);
    }

    // ─── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, Customer $customer)
    {
        $this->authorizeMerchant($request, $customer->merchant_id);

        $data = $request->validate([
            'customer_name'     => 'sometimes|string|max:255',
            'phone'             => 'nullable|string|max:20',
            'email'             => 'nullable|email',
            'default_address'   => 'sometimes|nullable|string',
            'default_latitude'  => 'nullable|numeric|between:-90,90',
            'default_longitude' => 'nullable|numeric|between:-180,180',
            'location_source'   => 'nullable|in:google_maps_link,manual_pin,address_geocoding,unknown',
            'google_maps_link'  => 'nullable|string|max:2000',
            'vip_level'         => 'nullable|in:standard,silver,gold,platinum',
            'cluster'           => 'nullable|string|max:50',
            'notes'             => 'nullable|string',
            'is_active'         => 'nullable|boolean',
        ]);

        if (array_key_exists('cluster', $data) && empty($data['cluster'])) {
            $name = $data['customer_name'] ?? $customer->customer_name;
            $data['cluster'] = $this->detectCluster($customer->merchant_id, $name);
        }

        // Fetch settings early — needed for location change threshold
        $settings        = MerchantSetting::where('merchant_id', $customer->merchant_id)->first();
        $changeThreshold = (float) ($settings?->location_change_warning_radius ?? 2.0);

        // Capture previous coordinates before resolving new ones
        $prevLat = $customer->default_latitude;
        $prevLng = $customer->default_longitude;

        [$data] = $this->resolveLocationSource($data, $customer, $customer->merchant_id);

        // Detect significant coordinate change using the merchant-configured threshold
        $locationChangeWarning = null;
        if (
            $prevLat && $prevLng
            && isset($data['default_latitude'], $data['default_longitude'])
            && ($data['default_latitude'] != $prevLat || $data['default_longitude'] != $prevLng)
        ) {
            $distance = LocationService::distance(
                $prevLat, $prevLng,
                $data['default_latitude'], $data['default_longitude']
            );
            if ($distance >= $changeThreshold) {
                $locationChangeWarning = [
                    'distance_km'     => round($distance, 1),
                    'previous_coords' => ['lat' => $prevLat, 'lng' => $prevLng],
                    'new_coords'      => ['lat' => $data['default_latitude'], 'lng' => $data['default_longitude']],
                    'threshold_km'    => $changeThreshold,
                ];
            }
        }

        $customer->update($data);
        $fresh = $customer->fresh();

        return response()->json([
            'data'                    => $fresh,
            'depot_distance'          => $this->depotDistance($fresh, $settings),
            'location_confidence'     => $fresh->locationConfidence(),
            'location_change_warning' => $locationChangeWarning,
            'warnings'                => $this->buildWarnings($fresh, $settings),
        ]);
    }

    // ─── Bulk / utility ────────────────────────────────────────────────────────

    public function bulkUpdateCluster(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $request->validate([
            'customer_ids'   => 'required|array|min:1',
            'customer_ids.*' => 'integer|exists:customers,id',
            'cluster'        => 'required|string|max:50',
        ]);

        $updated = Customer::where('merchant_id', $merchantId)
            ->whereIn('id', $request->customer_ids)
            ->update(['cluster' => $request->cluster]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    public function deduplicate(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $duplicateNames = Customer::where('merchant_id', $merchantId)
            ->select('customer_name')
            ->groupBy('customer_name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('customer_name');

        if ($duplicateNames->isEmpty()) {
            return response()->json(['data' => ['deleted' => 0, 'groups' => 0]]);
        }

        $deleted = 0;

        foreach ($duplicateNames as $name) {
            DB::transaction(function () use ($merchantId, $name, &$deleted) {
                $dupes = Customer::where('merchant_id', $merchantId)
                    ->where('customer_name', $name)
                    ->orderBy('id')
                    ->get();

                // Primary = most orders; tie-break = oldest id (most likely the "real" record)
                $primary      = $dupes->sortByDesc('total_orders')->first();
                $duplicateIds = $dupes->pluck('id')->filter(fn($id) => $id !== $primary->id)->values();
                $dupeRecords  = $dupes->filter(fn($c) => $c->id !== $primary->id);

                // 1. Reassign orders to primary
                DeliveryOrder::whereIn('customer_id', $duplicateIds)
                    ->update(['customer_id' => $primary->id]);

                // 2. Reassign timeline events to primary (preserve full history)
                DB::table('customer_timelines')
                    ->whereIn('customer_id', $duplicateIds)
                    ->update(['customer_id' => $primary->id]);

                // 3. Merge tags — copy any tag the primary doesn't already have
                $existingTagIds = DB::table('customer_tag_assignments')
                    ->where('customer_id', $primary->id)
                    ->pluck('tag_id')
                    ->flip();

                DB::table('customer_tag_assignments')
                    ->whereIn('customer_id', $duplicateIds)
                    ->get()
                    ->each(function ($row) use ($primary, $existingTagIds) {
                        if (!$existingTagIds->has($row->tag_id)) {
                            DB::table('customer_tag_assignments')->insert([
                                'customer_id' => $primary->id,
                                'tag_id'      => $row->tag_id,
                                'assigned_by' => $row->assigned_by,
                                'created_at'  => $row->created_at,
                            ]);
                        }
                    });

                // 4. Merge scalar fields — take first non-null/non-empty value across all records
                //    ordered: primary first, then duplicates oldest→newest
                $allByPriority = $dupes->sortByDesc(fn($c) => $c->id === $primary->id ? PHP_INT_MAX : $c->total_orders);

                $merged = [];

                // phone / email / notes — take primary's value; fall back to first non-empty duplicate
                foreach (['phone', 'email', 'notes'] as $field) {
                    if (empty($primary->$field)) {
                        $fallback = $dupeRecords->first(fn($c) => !empty($c->$field));
                        if ($fallback) {
                            $merged[$field] = $fallback->$field;
                        }
                    }
                }

                // vip_level — promote to highest tier found across all records
                $vipRank = ['standard' => 0, 'silver' => 1, 'gold' => 2, 'platinum' => 3];
                $highestVip = $allByPriority->reduce(function ($carry, $c) use ($vipRank) {
                    $rank = $vipRank[$c->vip_level ?? 'standard'] ?? 0;
                    return $rank > ($vipRank[$carry] ?? 0) ? ($c->vip_level ?? 'standard') : $carry;
                }, $primary->vip_level ?? 'standard');
                if ($highestVip !== ($primary->vip_level ?? 'standard')) {
                    $merged['vip_level'] = $highestVip;
                }

                // location — prefer highest-confidence GPS; fall back to oldest with coords
                $locationConfidenceRank = [
                    'google_maps_link' => 4,
                    'geocoded'         => 3,
                    'manual_pin'       => 2,
                    'imported'         => 1,
                    'unknown'          => 0,
                ];
                $bestLocation = $allByPriority->filter(fn($c) => $c->default_latitude !== null)
                    ->sortByDesc(fn($c) => $locationConfidenceRank[$c->location_source ?? 'unknown'] ?? 0)
                    ->first();
                if ($bestLocation && $bestLocation->id !== $primary->id) {
                    $merged['default_latitude']          = $bestLocation->default_latitude;
                    $merged['default_longitude']         = $bestLocation->default_longitude;
                    $merged['default_address']           = $bestLocation->default_address ?: $primary->default_address;
                    $merged['location_source']           = $bestLocation->location_source;
                    $merged['location_last_verified_at'] = $bestLocation->location_last_verified_at;
                }

                // cluster — keep primary's; fall back to any duplicate that has one
                if (empty($primary->cluster)) {
                    $withCluster = $dupeRecords->first(fn($c) => !empty($c->cluster));
                    if ($withCluster) {
                        $merged['cluster'] = $withCluster->cluster;
                    }
                }

                // created_at — use the oldest across all records
                $oldest = $dupes->sortBy('created_at')->first();
                if ($oldest->created_at < $primary->created_at) {
                    $merged['created_at'] = $oldest->created_at;
                }

                if (!empty($merged)) {
                    DB::table('customers')->where('id', $primary->id)->update($merged);
                }

                // 5. Delete duplicate customer_profiles (cascade would remove them anyway,
                //    but explicit delete keeps intent clear before customer delete)
                DB::table('customer_profiles')->whereIn('customer_id', $duplicateIds)->delete();

                // 6. Delete duplicate customers (cascades remaining FK children)
                $deleted += Customer::whereIn('id', $duplicateIds)->delete();

                // 7. Rebuild the primary's profile + health + segment from the now-merged orders
                $primary->refresh();
                $this->rebuilder->rebuild($primary);
            });
        }

        return response()->json(['data' => ['deleted' => $deleted, 'groups' => $duplicateNames->count()]]);
    }

    public function destroy(Request $request, Customer $customer)
    {
        $this->authorizeMerchant($request, $customer->merchant_id);
        $customer->delete();
        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $merchantId = $request->user()->merchant_id;

        $deleted = Customer::where('merchant_id', $merchantId)
            ->whereIn('id', $request->ids)
            ->delete();

        return response()->json(['message' => "{$deleted} customer(s) deleted."]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $merchantId = $request->user()->merchant_id;

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if (empty($rows)) {
            return response()->json(['message' => 'The file is empty.'], 422);
        }

        $header = array_map(fn($h) => strtolower(trim((string) $h)), $rows[0]);
        $columnMap = [
            'customer_name'   => ['customer_name', 'name', 'nama', 'nama pelanggan'],
            'phone'           => ['phone', 'phone_number', 'telepon', 'no telepon', 'no hp'],
            'email'           => ['email'],
            'default_address' => ['default_address', 'address', 'alamat'],
            'vip_level'       => ['vip_level', 'vip', 'level'],
            'notes'           => ['notes', 'note', 'catatan'],
        ];

        $columnIndex = [];
        foreach ($columnMap as $field => $aliases) {
            foreach ($header as $idx => $colName) {
                if (in_array($colName, $aliases, true)) {
                    $columnIndex[$field] = $idx;
                    break;
                }
            }
        }

        if (!isset($columnIndex['customer_name'])) {
            return response()->json(['message' => 'The file must contain a "customer_name" (or "name") column.'], 422);
        }

        $validVipLevels = ['standard', 'silver', 'gold', 'platinum'];
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $get = fn($field) => isset($columnIndex[$field]) ? trim((string) ($row[$columnIndex[$field]] ?? '')) : '';

            $customerName = $get('customer_name');
            if ($customerName === '') {
                $skipped++;
                continue;
            }

            $vipLevel = strtolower($get('vip_level'));
            if (!in_array($vipLevel, $validVipLevels, true)) {
                $vipLevel = 'standard';
            }

            $address   = $get('default_address');
            $latitude  = null;
            $longitude = null;
            $locationSource = 'unknown';
            $locationVerifiedAt = null;

            if ($address !== '') {
                $geo = $this->geocoder->geocode($address, $merchantId);
                if ($geo) {
                    $latitude  = $geo['latitude'];
                    $longitude = $geo['longitude'];
                    $locationSource = 'address_geocoding';
                    $locationVerifiedAt = now();
                }
            }

            try {
                Customer::create([
                    'ulid'                       => Str::ulid(),
                    'merchant_id'                => $merchantId,
                    'customer_name'              => $customerName,
                    'phone'                      => $get('phone') ?: null,
                    'email'                      => $get('email') ?: null,
                    'default_address'            => $address ?: null,
                    'default_latitude'           => $latitude,
                    'default_longitude'          => $longitude,
                    'location_source'            => $locationSource,
                    'location_last_verified_at'  => $locationVerifiedAt,
                    'vip_level'                  => $vipLevel,
                    'cluster'                    => $get('cluster') ?: $this->detectCluster($merchantId, $customerName),
                    'notes'                      => $get('notes') ?: null,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Row " . ($i + 1) . ": " . $e->getMessage();
            }
        }

        return response()->json([
            'message'  => "Imported {$imported} customer(s), skipped {$skipped}.",
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => array_slice($errors, 0, 10),
        ]);
    }

    public function downloadTemplate()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['customer_name', 'phone', 'email', 'default_address', 'vip_level', 'notes'];
        $sample  = ['Budi Santoso', '081234567890', 'budi@example.com', 'Jl. Sudirman No. 1 Bandung', 'standard', 'Pelanggan tetap'];
        $cols    = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach ($headers as $i => $header) {
            $col = $cols[$i];
            $sheet->getCell($col . '1')->setValue($header);
            $sheet->getStyle($col . '1')->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD0E4F7']],
            ]);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        foreach ($sample as $i => $value) {
            $sheet->getCell($cols[$i] . '2')->setValue($value);
        }

        $sheet->setTitle('Customers');
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'customers_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
            ->select(['id', 'ulid', 'customer_name', 'phone', 'default_address',
                      'default_latitude', 'default_longitude', 'vip_level', 'location_source'])
            ->orderBy('customer_name')
            ->limit($request->limit ?? 10)
            ->get();

        return response()->json(['data' => $results]);
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    private function detectCluster(int $merchantId, string $name): string
    {
        $clusters = MerchantCluster::namesForMerchant($merchantId);

        foreach ($clusters as $cluster) {
            if (stripos($name, $cluster) !== false) {
                return $cluster;
            }
        }

        return 'no cluster';
    }

    /**
     * Resolves location source and sets coordinates + metadata on $data.
     * Priority: google_maps_link > explicit lat/lng (manual_pin) > address geocoding.
     *
     * location_last_verified_at is updated ONLY when coordinates actually change.
     * Edits to name, phone, notes, etc. must not touch the verification timestamp.
     */
    private function resolveLocationSource(array $data, ?Customer $existing, int $merchantId): array
    {
        // 1. Google Maps link extraction
        if (!empty($data['google_maps_link'])) {
            $coords = $this->mapsLink->extractCoordinates($data['google_maps_link']);
            if ($coords) {
                $data['default_latitude']  = $coords['latitude'];
                $data['default_longitude'] = $coords['longitude'];
                $data['location_source']   = 'google_maps_link';
                if ($this->coordinatesChanged($data, $existing)) {
                    $data['location_last_verified_at'] = now();
                }
            }
            unset($data['google_maps_link']);
            return [$data];
        }

        // 2. Explicit coordinates sent by client
        if (!empty($data['default_latitude'])) {
            $data['location_source'] = $data['location_source'] ?? 'manual_pin';
            if ($this->coordinatesChanged($data, $existing)) {
                $data['location_last_verified_at'] = now();
            }
            return [$data];
        }

        // 3. Auto-geocode from address (only when no explicit coordinates provided)
        $address = $data['default_address'] ?? ($existing ? $existing->default_address : null);
        if (!empty($address) && empty($data['default_latitude'])) {
            $geo = $this->geocoder->geocode($address, $merchantId);
            if ($geo) {
                $data['default_latitude']  = $geo['latitude'];
                $data['default_longitude'] = $geo['longitude'];
                $data['location_source']   = 'address_geocoding';
                if ($this->coordinatesChanged($data, $existing)) {
                    $data['location_last_verified_at'] = now();
                }
            }
        }

        return [$data];
    }

    /**
     * Returns true when the incoming coordinates differ from the existing record.
     * For new records ($existing === null) any non-empty coordinates count as changed.
     * Uses 7-decimal-place precision (~1 cm) to avoid float comparison noise.
     */
    private function coordinatesChanged(array $data, ?Customer $existing): bool
    {
        if ($existing === null) {
            return !empty($data['default_latitude']);
        }
        return round((float) ($data['default_latitude']  ?? 0), 7) !== round((float) $existing->default_latitude,  7)
            || round((float) ($data['default_longitude'] ?? 0), 7) !== round((float) $existing->default_longitude, 7);
    }

    /**
     * Builds the structured warnings array for store/update responses.
     * Returns an empty array when there are no warnings (never null).
     */
    private function buildWarnings(Customer $customer, ?MerchantSetting $settings): array
    {
        $warnings = [];
        $info = $this->depotDistance($customer, $settings);

        if ($info && $info['exceeds_radius']) {
            $warnings[] = [
                'type'        => 'depot_distance',
                'distance_km' => $info['distance_km'],
                'radius_km'   => $info['radius_km'],
                'message'     => 'Customer location exceeds your configured service radius.',
            ];
        }

        return $warnings;
    }

    /**
     * Returns depot distance info, or null if depot/customer has no coordinates.
     */
    private function depotDistance(Customer $customer, ?MerchantSetting $settings): ?array
    {
        if (
            !$customer->default_latitude
            || !$customer->default_longitude
            || !$settings?->depot_latitude
            || !$settings?->depot_longitude
        ) {
            return null;
        }

        $distance = LocationService::distance(
            $customer->default_latitude, $customer->default_longitude,
            $settings->depot_latitude, $settings->depot_longitude
        );

        $radius = $settings->location_validation_radius ?? 30;

        return [
            'distance_km'  => round($distance, 2),
            'radius_km'    => $radius,
            'exceeds_radius' => $distance > $radius,
        ];
    }
}
