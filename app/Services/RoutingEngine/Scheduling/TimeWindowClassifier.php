<?php

namespace App\Services\RoutingEngine\Scheduling;

class TimeWindowClassifier
{
    public const HIGH     = 'HIGH';
    public const NORMAL   = 'NORMAL';
    public const FLEXIBLE = 'FLEXIBLE';

    private int $highThreshold = 150;

    /**
     * Classify each scored order by time-window urgency tier.
     * HIGH: score >= 150 (VIP + time-sensitive)
     * NORMAL: score > 0
     * FLEXIBLE: score == 0
     *
     * @param  array  $scoredOrders  [orderId => ['total_score' => int, ...]]
     * @return array                 [orderId => 'HIGH'|'NORMAL'|'FLEXIBLE']
     */
    public function classify(array $scoredOrders): array
    {
        $result = [];
        foreach ($scoredOrders as $orderId => $scored) {
            $total = $scored['total_score'] ?? 0;
            if ($total >= $this->highThreshold) {
                $result[$orderId] = self::HIGH;
            } elseif ($total > 0) {
                $result[$orderId] = self::NORMAL;
            } else {
                $result[$orderId] = self::FLEXIBLE;
            }
        }
        return $result;
    }

    /**
     * Re-order $orderIds so HIGH precedes NORMAL precedes FLEXIBLE.
     * Stable within each tier (preserves NN-determined sub-order).
     */
    public function sortByTier(array $orderIds, array $classified): array
    {
        $weight = [self::HIGH => 0, self::NORMAL => 1, self::FLEXIBLE => 2];

        usort($orderIds, function ($a, $b) use ($classified, $weight) {
            return ($weight[$classified[$a] ?? self::FLEXIBLE]) <=> ($weight[$classified[$b] ?? self::FLEXIBLE]);
        });

        return $orderIds;
    }
}
