<?php

namespace App\Providers;

use App\Events\OrderStatusChanged;
use App\Events\RouteGenerated;
use App\Listeners\LogOrderStatusChange;
use App\Listeners\LogRouteGenerated;
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
        Event::listen(OrderStatusChanged::class, LogOrderStatusChange::class);
        Event::listen(RouteGenerated::class, LogRouteGenerated::class);
    }
}
