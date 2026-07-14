<?php

namespace App\Events;

use App\Models\DeliveryOrder;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DeliveryOrder $order,
        public readonly string        $fromStatus,
        public readonly string        $toStatus,
        public readonly User          $actor,
        public readonly array         $context = [],
    ) {}
}
