<?php

namespace App\Services\RoutingEngine\Scoring;

use App\Models\DeliveryOrder;
use Carbon\Carbon;

class WindowScorer
{
    public function score(DeliveryOrder $order): float
    {
        if (!$order->requested_delivery_start || !$order->requested_delivery_date) {
            return 0.0;
        }

        $date = $order->requested_delivery_date->format('Y-m-d');
        $windowStart = Carbon::parse("{$date} {$order->requested_delivery_start}");
        $windowEnd   = $order->requested_delivery_end
            ? Carbon::parse("{$date} {$order->requested_delivery_end}")
            : $windowStart->copy()->addHour();

        $now = now();
        $minutesToStart = $now->diffInMinutes($windowStart, false); // negative = start is in past
        $minutesToEnd   = $now->diffInMinutes($windowEnd, false);   // negative = end is in past

        // Window is currently active
        if ($minutesToStart <= 0 && $minutesToEnd > 0) {
            return 150.0;
        }

        // Window opens in < 30 min → URGENT
        if ($minutesToStart > 0 && $minutesToStart <= 30) {
            return 200.0;
        }

        // Window opens in 30–60 min
        if ($minutesToStart > 30 && $minutesToStart <= 60) {
            return 100.0;
        }

        // Window opens in 1–3 hours
        if ($minutesToStart > 60 && $minutesToStart <= 180) {
            return 50.0;
        }

        // Far future window
        if ($minutesToStart > 180) {
            return 20.0;
        }

        // Window missed
        return -50.0;
    }
}
