<?php

namespace App\Events;

use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\RouteStop;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StopCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RouteStop     $stop,
        public readonly DeliveryOrder $order,
        public readonly Driver        $driver,
        public readonly User          $actor,
        public readonly string        $outcome, // 'delivered' | 'failed'
    ) {}
}
