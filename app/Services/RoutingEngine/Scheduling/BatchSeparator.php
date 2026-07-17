<?php

namespace App\Services\RoutingEngine\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class BatchSeparator
{
    public const MORNING   = 'morning';
    public const AFTERNOON = 'afternoon';
    public const LATE      = 'late';

    /**
     * Assign each order to a time-of-day delivery batch.
     * Uses delivery_window_start when present; falls back to order_created_at hour.
     *
     * @return array  [orderId => 'morning'|'afternoon'|'late']
     */
    public function separate(Collection $orders): array
    {
        $result = [];
        foreach ($orders as $order) {
            $result[$order->id] = $this->resolveBatch($order);
        }
        return $result;
    }

    public function batchOrder(): array
    {
        return [self::MORNING, self::AFTERNOON, self::LATE];
    }

    private function resolveBatch(mixed $order): string
    {
        $windowStart = $order->delivery_window_start ?? null;

        if ($windowStart) {
            try {
                $hour = Carbon::parse($windowStart)->hour;
            } catch (\Throwable) {
                $hour = 0;
            }
        } else {
            $created = $order->order_created_at ?? $order->created_at;
            $hour    = $created ? (int) $created->format('H') : 0;
        }

        if ($hour >= 17) return self::LATE;
        if ($hour >= 12) return self::AFTERNOON;
        return self::MORNING;
    }
}
