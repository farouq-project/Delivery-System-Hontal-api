<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\BusinessLogger;
use App\Services\Geocoding\GoogleGeocodingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Background job for geocoding a customer's default address.
 *
 * Infrastructure is prepared here for future use. The customer import currently
 * geocodes synchronously (N API calls per import). When queues are configured
 * and the import is updated, this job should be dispatched per row instead.
 *
 * To wire in: in CustomerController::import(), after creating each Customer row
 * with lat/lng = null, dispatch:
 *   GeocodeCustomerAddress::dispatch($customer);
 *
 * Queue: 'geocoding' (configure a dedicated worker with low concurrency to
 * respect Google's rate limits).
 */
class GeocodeCustomerAddress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 10; // seconds between retries

    public function __construct(private readonly Customer $customer) {}

    public function handle(GoogleGeocodingService $geocoder): void
    {
        // Skip if the customer already has coordinates (e.g. set manually after dispatch)
        if ($this->customer->default_latitude !== null) {
            return;
        }

        if (empty($this->customer->default_address)) {
            return;
        }

        $geo = $geocoder->geocode($this->customer->default_address);

        if ($geo) {
            $this->customer->update([
                'default_latitude'  => $geo['latitude'],
                'default_longitude' => $geo['longitude'],
            ]);

            BusinessLogger::googleApiCall(
                'geocoding',
                $this->customer->merchant_id,
                1,
                true,
                'queue:GeocodeCustomerAddress',
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        BusinessLogger::googleApiCall(
            'geocoding',
            $this->customer->merchant_id,
            1,
            false,
            'queue:GeocodeCustomerAddress:failed',
        );
    }
}
