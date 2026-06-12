<?php

namespace App\Services\RoutingEngine\Scoring;

use App\Models\DeliveryOrder;

class WaitingScorer
{
    public function score(DeliveryOrder $order): float
    {
        $createdAt = $order->order_created_at ?? $order->created_at;
        $hoursWaiting = $createdAt->diffInMinutes(now()) / 60;

        return match (true) {
            $hoursWaiting < 1  => 5.0,
            $hoursWaiting < 3  => 20.0,
            $hoursWaiting < 6  => 40.0,
            $hoursWaiting < 10 => 70.0,
            default            => 100.0,
        };
    }
}
