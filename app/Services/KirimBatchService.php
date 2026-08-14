<?php

namespace App\Services;

use App\Models\HontalKirimBatch;
use Carbon\Carbon;

class KirimBatchService
{
    /**
     * Returns the :00 or :30 window start that covers the given time.
     * floor(minute / 30) * 30 → always 0 or 30.
     */
    public function computeWindowStart(Carbon $time): Carbon
    {
        $slot = (int) floor($time->minute / 30) * 30;

        return $time->copy()->setMinute($slot)->setSecond(0)->setMicrosecond(0);
    }

    public function computeWindowEnd(Carbon $windowStart): Carbon
    {
        return $windowStart->copy()->addMinutes(30);
    }

    public function getCurrentWindowStart(): Carbon
    {
        return $this->computeWindowStart(now());
    }

    /**
     * Returns the currently open batch for this window, or null if none exists.
     */
    public function getCurrentOpenBatch(): ?HontalKirimBatch
    {
        $windowStart = $this->getCurrentWindowStart();

        return HontalKirimBatch::where('window_start', $windowStart)
            ->where('status', 'open')
            ->first();
    }

    /**
     * Returns the open batch for the current 30-min window, creating one if needed.
     */
    public function getOrOpenBatch(): HontalKirimBatch
    {
        $windowStart = $this->getCurrentWindowStart();
        $windowEnd   = $this->computeWindowEnd($windowStart);

        return HontalKirimBatch::firstOrCreate(
            ['window_start' => $windowStart],
            ['window_end' => $windowEnd, 'status' => 'open'],
        );
    }

    /**
     * Closes all open batches whose window_end has passed.
     * Called by the kirim:close-batch cron at :00 and :30.
     * Returns the number of batches closed.
     */
    public function closeExpiredBatches(): int
    {
        return HontalKirimBatch::where('status', 'open')
            ->where('window_end', '<=', now())
            ->update([
                'status'    => 'closed',
                'closed_at' => now(),
                // closed_by = null → auto-closed by cron
            ]);
    }
}
