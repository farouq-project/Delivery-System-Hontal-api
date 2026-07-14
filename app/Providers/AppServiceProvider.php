<?php

namespace App\Providers;

use App\Events\CustomerCreated;
use App\Events\CustomerUpdated;
use App\Events\OrderStatusChanged;
use App\Events\RouteGenerated;
use App\Listeners\InitializeCustomerProfile;
use App\Listeners\LogOrderStatusChange;
use App\Listeners\LogRouteGenerated;
use App\Listeners\TrackCustomerDataChanges;
use App\Listeners\UpdateCustomerProfileOnOrder;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Observers\CustomerObserver;
use App\Observers\DeliveryOrderObserver;
use App\Services\FeatureManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureManager::class);
    }

    public function boot(): void
    {
        // Phase 1 — core event listeners
        Event::listen(OrderStatusChanged::class, LogOrderStatusChange::class);
        Event::listen(RouteGenerated::class, LogRouteGenerated::class);

        // Phase 2A — customer domain event listeners
        Event::listen(CustomerCreated::class, InitializeCustomerProfile::class);
        Event::listen(CustomerUpdated::class, TrackCustomerDataChanges::class);
        Event::listen(OrderStatusChanged::class, UpdateCustomerProfileOnOrder::class);

        // Phase 2A — model observers (additive, no controller changes required)
        Customer::observe(CustomerObserver::class);
        DeliveryOrder::observe(DeliveryOrderObserver::class);
    }
}
