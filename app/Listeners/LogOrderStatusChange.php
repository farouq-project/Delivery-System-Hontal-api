<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Services\BusinessLogger;

class LogOrderStatusChange
{
    public function handle(OrderStatusChanged $event): void
    {
        BusinessLogger::orderStatusChanged(
            $event->order,
            $event->fromStatus,
            $event->toStatus,
            $event->actor->role,
        );
    }
}
