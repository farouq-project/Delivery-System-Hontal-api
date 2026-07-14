<?php

namespace App\Listeners;

use App\Events\RouteGenerated;
use App\Services\BusinessLogger;

class LogRouteGenerated
{
    public function handle(RouteGenerated $event): void
    {
        BusinessLogger::routeGenerated(
            $event->route,
            $event->merchant,
            $event->actor->role,
            $event->orderCount,
        );
    }
}
