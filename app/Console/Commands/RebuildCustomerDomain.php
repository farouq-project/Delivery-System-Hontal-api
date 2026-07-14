<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MerchantFeature;
use App\Models\Scopes\MerchantScope;
use App\Services\CustomerDomainRebuildService;
use Illuminate\Console\Command;

class RebuildCustomerDomain extends Command
{
    protected $signature = 'customers:rebuild
        {--merchant=   : Rebuild only this merchant ID}
        {--customer=   : Rebuild only this customer ID}
        {--dry-run     : Compute without writing to database}
        {--chunk=200   : Customers per processing chunk}';

    protected $description = 'Rebuild Customer Domain (profile, health, segment) from delivery_orders source of truth';

    public function __construct(
        private readonly CustomerDomainRebuildService $rebuildService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt   = now();
        $dryRun      = (bool) $this->option('dry-run');
        $chunkSize   = max(1, (int) ($this->option('chunk') ?? 200));
        $merchantOpt = $this->option('merchant');
        $customerOpt = $this->option('customer');

        if ($dryRun) {
            $this->line('<fg=yellow>[DRY RUN] No data will be written to the database.</fg=yellow>');
        }

        $counters = [
            'processed'       => 0,
            'updated'         => 0,
            'profiles_rebuilt'=> 0,
            'health_updated'  => 0,
            'segments_updated'=> 0,
            'errors'          => 0,
            'skipped'         => 0,
        ];

        // ── Single-customer mode ──────────────────────────────────────────────
        if ($customerOpt) {
            $customer = Customer::withoutGlobalScope(MerchantScope::class)
                ->find((int) $customerOpt);

            if (!$customer) {
                $this->error("Customer #{$customerOpt} not found.");
                return self::FAILURE;
            }

            $this->info("Rebuilding customer #{$customer->id} — {$customer->customer_name}");
            $this->processCustomer($customer, $dryRun, $counters);
            $this->printSummary($counters, $startedAt, $dryRun);
            return self::SUCCESS;
        }

        // ── Merchant or all-merchants mode ────────────────────────────────────
        $merchantIds = $this->resolveMerchantIds($merchantOpt);

        if ($merchantIds->isEmpty()) {
            $this->warn('No merchants with customer_domain feature enabled found.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Rebuilding Customer Domain for %d merchant(s)%s',
            $merchantIds->count(),
            $dryRun ? ' [DRY RUN]' : ''
        ));

        foreach ($merchantIds as $merchantId) {
            $this->line("  Merchant #{$merchantId}...");

            Customer::withoutGlobalScope(MerchantScope::class)
                ->where('merchant_id', $merchantId)
                ->whereNull('deleted_at')
                ->chunkById($chunkSize, function ($customers) use ($dryRun, &$counters) {
                    foreach ($customers as $customer) {
                        $this->processCustomer($customer, $dryRun, $counters);
                    }
                });
        }

        $this->printSummary($counters, $startedAt, $dryRun);
        return self::SUCCESS;
    }

    private function processCustomer(Customer $customer, bool $dryRun, array &$counters): void
    {
        $counters['processed']++;

        $result = $this->rebuildService->rebuild($customer, $dryRun);

        if ($result['error']) {
            $counters['errors']++;
            $this->warn("  [ERROR] Customer #{$customer->id}: {$result['error']}");
            return;
        }

        $counters['profiles_rebuilt']++;

        if ($result['updated'])         $counters['updated']++;
        if ($result['health_updated'])  $counters['health_updated']++;
        if ($result['segment_updated']) $counters['segments_updated']++;

        if ($result['updated'] && $this->output->isVerbose()) {
            $b = $result['before'];
            $a = $result['after'];
            $this->line(sprintf(
                '  Customer #%d %s: orders %s→%s, spending %s→%s, health %s→%s, segment %s→%s',
                $customer->id,
                $customer->customer_name,
                $b['total_orders'] ?? '?',    $a['total_orders'] ?? '?',
                $b['total_spending'] ?? '?',  $a['total_spending'] ?? '?',
                $b['health_status'] ?? '?',   $a['health_status'] ?? '?',
                $b['segment'] ?? '?',         $a['segment'] ?? '?',
            ));
        }
    }

    private function resolveMerchantIds(?string $merchantOpt)
    {
        if ($merchantOpt) {
            return collect([(int) $merchantOpt]);
        }

        return MerchantFeature::where('feature', 'customer_domain')
            ->where('is_enabled', true)
            ->pluck('merchant_id');
    }

    private function printSummary(array $counters, $startedAt, bool $dryRun): void
    {
        $elapsed = number_format(now()->diffInSeconds($startedAt), 0);

        $this->newLine();
        $this->line('═══════════════════════════════════════════');
        $this->line(sprintf('  <fg=green>Customer Domain Rebuild %s</fg=green>', $dryRun ? '(DRY RUN)' : 'Complete'));
        $this->line('═══════════════════════════════════════════');
        $this->line("  Customers Processed : {$counters['processed']}");
        $this->line("  Profiles Rebuilt    : {$counters['profiles_rebuilt']}");
        $this->line("  Records Updated     : {$counters['updated']}");
        $this->line("  Health Updated      : {$counters['health_updated']}");
        $this->line("  Segments Updated    : {$counters['segments_updated']}");
        $this->line("  Errors              : {$counters['errors']}");
        $this->line("  Execution Time      : {$elapsed}s");
        $this->line('═══════════════════════════════════════════');

        if ($dryRun) {
            $this->line('<fg=yellow>  No changes were written. Re-run without --dry-run to apply.</fg=yellow>');
        }

        if ($counters['errors'] > 0) {
            $this->warn("  {$counters['errors']} customer(s) failed. Check logs for details.");
        }
    }
}
