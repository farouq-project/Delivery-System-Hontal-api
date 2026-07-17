<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteTrialMerchant extends Command
{
    protected $signature = 'trial:delete {--merchant-id= : The merchant ID to delete}';
    protected $description = 'Completely remove a trial merchant and all its data';

    public function handle(): int
    {
        $merchantId = $this->option('merchant-id');

        if (!$merchantId) {
            $this->error('Please provide --merchant-id');
            return self::FAILURE;
        }

        $merchant = Merchant::find($merchantId);
        if (!$merchant) {
            $this->error("Merchant #{$merchantId} not found.");
            return self::FAILURE;
        }

        // Refuse to delete production merchants (slug doesn't end in a 4-digit demo suffix)
        if (!str_contains($merchant->email, '.demo')) {
            $this->error("Merchant #{$merchantId} does not appear to be a trial merchant (no .demo email).");
            $this->error('To force delete a production merchant, do it manually through the database.');
            return self::FAILURE;
        }

        $confirmed = $this->confirm(
            "Delete {$merchant->company_name} ({$merchant->email}) and ALL its data?",
            false
        );

        if (!$confirmed) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $this->info("Deleting {$merchant->company_name}…");

        DB::transaction(function () use ($merchant) {
            // Delete in dependency order
            DB::table('route_stops')->whereIn('route_id', function ($q) use ($merchant) {
                $q->select('id')->from('routes')->where('merchant_id', $merchant->id);
            })->delete();

            DB::table('route_assignments')->whereIn('route_id', function ($q) use ($merchant) {
                $q->select('id')->from('routes')->where('merchant_id', $merchant->id);
            })->delete();

            DB::table('routes')->where('merchant_id', $merchant->id)->delete();
            DB::table('delivery_orders')->where('merchant_id', $merchant->id)->delete();
            DB::table('customer_profiles')->whereIn('customer_id', function ($q) use ($merchant) {
                $q->select('id')->from('customers')->where('merchant_id', $merchant->id);
            })->delete();
            DB::table('customers')->where('merchant_id', $merchant->id)->delete();
            DB::table('drivers')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_branches')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_settings')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_features')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_subscriptions')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_activity_log')->where('merchant_id', $merchant->id)->delete();
            DB::table('users')->where('merchant_id', $merchant->id)->delete();
            $merchant->delete();
        });

        $this->info('Trial merchant deleted successfully.');
        return self::SUCCESS;
    }
}
