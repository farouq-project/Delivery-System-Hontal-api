<?php

namespace App\Console\Commands;

use App\Services\KirimBatchService;
use Illuminate\Console\Command;

class CloseKirimBatchCommand extends Command
{
    protected $signature   = 'kirim:close-batch';
    protected $description = 'Close expired Hontal Kirim batch windows and open the next one';

    public function handle(KirimBatchService $batchService): int
    {
        $closed = $batchService->closeExpiredBatches();

        if ($closed > 0) {
            $this->info("Closed {$closed} expired Kirim batch(es).");
        }

        // Pre-open the next batch so the first order of a window never waits
        $batch = $batchService->getOrOpenBatch();
        $this->line("Current open batch: #{$batch->id} [{$batch->window_start} → {$batch->window_end}]");

        return self::SUCCESS;
    }
}
