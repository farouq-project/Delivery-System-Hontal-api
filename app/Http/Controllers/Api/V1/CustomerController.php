<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Geocoding\GoogleGeocodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

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
            'default_address'    => 'nullable|string',
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
            'default_address'   => 'sometimes|nullable|string',
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

        // First row = header. Map known column names (case-insensitive) to indexes.
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

            $address = $get('default_address');
            $latitude = null;
            $longitude = null;

            if ($address !== '') {
                $geo = $this->geocoder->geocode($address);
                if ($geo) {
                    $latitude  = $geo['latitude'];
                    $longitude = $geo['longitude'];
                }
            }

            try {
                Customer::create([
                    'ulid'              => Str::ulid(),
                    'merchant_id'       => $merchantId,
                    'customer_name'     => $customerName,
                    'phone'             => $get('phone') ?: null,
                    'email'             => $get('email') ?: null,
                    'default_address'   => $address ?: null,
                    'default_latitude'  => $latitude,
                    'default_longitude' => $longitude,
                    'vip_level'         => $vipLevel,
                    'notes'             => $get('notes') ?: null,
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
