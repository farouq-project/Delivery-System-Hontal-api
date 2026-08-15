<?php

namespace App\Http\Controllers\Api\V1\Kirim;

use App\Http\Controllers\Controller;
use App\Models\HontalKirimCredit;
use App\Models\HontalKirimCreditTransaction;
use App\Models\MerchantFeature;
use App\Services\KirimCreditService;
use Illuminate\Http\Request;

class KirimCreditController extends Controller
{
    public function __construct(private readonly KirimCreditService $creditService) {}

    public function balance(Request $request)
    {
        $merchantId = $request->user()->merchant_id;
        $fee        = $this->creditService->getFee();

        $credit = HontalKirimCredit::where('merchant_id', $merchantId)->first();

        $kirimEnabled = MerchantFeature::where('merchant_id', $merchantId)
            ->where('feature', 'hontal_kirim')
            ->where('is_enabled', true)
            ->exists();

        return response()->json([
            'data' => [
                'kirim_enabled'      => $kirimEnabled,
                'balance_idr'        => $credit?->balance_idr ?? 0,
                'fee_per_delivery'   => $fee,
                'deliveries_remaining' => $credit ? intdiv(max(0, $credit->balance_idr), $fee) : 0,
                'is_blocked'         => $credit ? $credit->balance_idr <= 0 : true,
                'low_balance'        => $credit ? $credit->balance_idr < $credit->low_balance_threshold_idr : false,
            ],
        ]);
    }

    public function transactions(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $txns = HontalKirimCreditTransaction::where('merchant_id', $merchantId)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($txns);
    }
}
