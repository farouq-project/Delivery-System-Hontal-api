<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function cashierSummary(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $merchantId = $request->user()->merchant_id;
        $startDate  = $request->start_date ?? now()->format('Y-m-d');
        $endDate    = $request->end_date ?? now()->format('Y-m-d');

        $rows = DeliveryOrder::where('merchant_id', $merchantId)
            ->whereNotNull('cashier_name')
            ->whereBetween(DB::raw('DATE(order_created_at)'), [$startDate, $endDate])
            ->select(
                'cashier_name',
                DB::raw("SUM(CASE WHEN payment_method = 'cash' THEN order_value ELSE 0 END) as total_cash"),
                DB::raw("SUM(CASE WHEN payment_method = 'transfer' THEN order_value ELSE 0 END) as total_transfer"),
                DB::raw("SUM(CASE WHEN payment_method = 'qris' THEN order_value ELSE 0 END) as total_qris"),
                DB::raw("SUM(CASE WHEN payment_method = 'bayar_di_toko' THEN order_value ELSE 0 END) as total_bayar_di_toko"),
                DB::raw('COUNT(*) as total_orders')
            )
            ->groupBy('cashier_name')
            ->orderBy('cashier_name')
            ->get()
            ->map(fn ($row) => [
                'cashier_name'         => $row->cashier_name,
                'total_cash'           => (float) $row->total_cash,
                'total_transfer'       => (float) $row->total_transfer,
                'total_qris'           => (float) $row->total_qris,
                'total_bayar_di_toko'  => (float) $row->total_bayar_di_toko,
                'total_orders'         => (int) $row->total_orders,
            ]);

        return response()->json([
            'data' => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'rows'       => $rows,
            ],
        ]);
    }
}
