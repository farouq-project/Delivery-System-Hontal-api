<?php

namespace App\Services;

use App\Models\HontalKirimCredit;
use App\Models\HontalKirimCreditTransaction;
use App\Models\PlatformSetting;
use App\Models\DeliveryOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KirimCreditService
{
    public function getOrCreateBalance(int $merchantId): HontalKirimCredit
    {
        $threshold = (int) (PlatformSetting::where('key', 'kirim_low_balance_threshold_idr')->value('value') ?: 50000);

        return HontalKirimCredit::firstOrCreate(
            ['merchant_id' => $merchantId],
            ['balance_idr' => 0, 'low_balance_threshold_idr' => $threshold],
        );
    }

    public function canCreateOrder(int $merchantId): bool
    {
        $credit = HontalKirimCredit::where('merchant_id', $merchantId)->first();
        if (!$credit) {
            return false; // No credit account = not provisioned for Kirim
        }
        return !$credit->isBlocked();
    }

    public function topup(int $merchantId, int $amountIdr, string $note, int $operatorUserId): HontalKirimCredit
    {
        return DB::transaction(function () use ($merchantId, $amountIdr, $note, $operatorUserId) {
            $credit = $this->getOrCreateBalance($merchantId);

            $credit->increment('balance_idr', $amountIdr);

            HontalKirimCreditTransaction::create([
                'merchant_id'    => $merchantId,
                'type'           => 'topup',
                'amount_idr'     => $amountIdr,
                'fee_amount_idr' => 0,
                'note'           => $note,
                'created_by'     => $operatorUserId,
            ]);

            $credit->refresh();

            // Reset low-balance notification flag when topped up above threshold
            if ($credit->balance_idr >= $credit->low_balance_threshold_idr && $credit->low_balance_notified_at) {
                $credit->update(['low_balance_notified_at' => null]);
            }

            return $credit;
        });
    }

    public function debit(int $merchantId, DeliveryOrder $order, int $actorUserId): void
    {
        DB::transaction(function () use ($merchantId, $order, $actorUserId) {
            $fee = $this->getFee();

            HontalKirimCredit::where('merchant_id', $merchantId)->lockForUpdate()->first();

            HontalKirimCredit::where('merchant_id', $merchantId)
                ->decrement('balance_idr', $fee);

            HontalKirimCreditTransaction::create([
                'merchant_id'    => $merchantId,
                'type'           => 'debit',
                'amount_idr'     => $fee,
                'fee_amount_idr' => $fee,
                'order_id'       => $order->id,
                'created_by'     => $actorUserId,
            ]);

            $credit = HontalKirimCredit::where('merchant_id', $merchantId)->first();
            $this->maybeSendLowBalanceAlert($credit);
        });
    }

    public function getFee(): int
    {
        return (int) (PlatformSetting::where('key', 'kirim_delivery_fee_idr')->value('value') ?: 10000);
    }

    private function maybeSendLowBalanceAlert(HontalKirimCredit $credit): void
    {
        if (!$credit->isLow()) {
            return;
        }

        // Only fire once per low-balance episode — reset when topped up
        if ($credit->low_balance_notified_at) {
            return;
        }

        $credit->update(['low_balance_notified_at' => now()]);

        // Log structured alert — Phase 4 will hook a real notification channel here
        Log::warning('hontal_kirim.low_balance', [
            'merchant_id'     => $credit->merchant_id,
            'balance_idr'     => $credit->balance_idr,
            'threshold_idr'   => $credit->low_balance_threshold_idr,
            'deliveries_left' => intdiv($credit->balance_idr, $this->getFee()),
        ]);
    }
}
