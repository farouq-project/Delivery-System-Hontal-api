<?php

namespace App\Services\RoutingEngine\Scoring;

class DistanceScorer
{
    /**
     * Score all orders based on their distance from reference point.
     * Closer = higher score (0–100).
     *
     * @param array $distances  [order_id => distance_m]
     * @return array            [order_id => score]
     */
    public function scoreAll(array $distances): array
    {
        if (empty($distances)) {
            return [];
        }

        $maxDistance = max($distances);
        if ($maxDistance === 0) {
            return array_fill_keys(array_keys($distances), 100.0);
        }

        $scores = [];
        foreach ($distances as $orderId => $distanceM) {
            $scores[$orderId] = (1 - ($distanceM / $maxDistance)) * 100;
        }

        return $scores;
    }
}
