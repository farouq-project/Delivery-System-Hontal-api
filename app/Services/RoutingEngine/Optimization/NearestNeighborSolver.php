<?php

namespace App\Services\RoutingEngine\Optimization;

class NearestNeighborSolver
{
    /**
     * Build route order using score-weighted nearest neighbor.
     *
     * @param array $start       ['lat' => float, 'lng' => float]
     * @param array $stops       [order_id => ['lat', 'lng', 'total_score']]
     * @param array $distMatrix  [from_idx => [to_idx => ['distance_m', 'duration_min']]]
     * @param array $indexMap    [order_id => matrix_index]
     * @return array             Ordered list of order_ids
     */
    /**
     * @param bool $groupAffinity  When true, same-group stops get a 40% effective-distance
     *                             discount. Pass false for batch-2 (>60 min) orders so they
     *                             route purely by distance + score.
     */
    public function solve(array $start, array $stops, array $distMatrix, array $indexMap, bool $groupAffinity = true): array
    {
        if (empty($stops)) return [];

        $ordered         = [];
        $remaining       = $stops;
        $currentIdx      = 0;    // depot/start is index 0
        $currentGroupKey = null; // group key of the last visited stop

        while (!empty($remaining)) {
            $bestOrderId   = null;
            $bestEffective = PHP_FLOAT_MAX;

            foreach ($remaining as $orderId => $stop) {
                $stopIdx = $indexMap[$orderId] ?? null;
                if ($stopIdx === null) continue;

                $raw        = $distMatrix[$currentIdx][$stopIdx]['distance_m'] ?? PHP_INT_MAX;
                $scoreBoost = ($stop['total_score'] ?? 0) / 100.0;
                $effective  = $raw / (1 + $scoreBoost);

                // Group affinity (batch-1 only): 40% discount for same group_key.
                // group_key is the named cluster if set, else first 6 chars of name.
                if ($groupAffinity && $currentGroupKey !== null) {
                    $stopGroupKey = $stop['group_key'] ?? null;
                    if ($stopGroupKey !== null && $stopGroupKey === $currentGroupKey) {
                        $effective *= 0.6;
                    }
                }

                if ($effective < $bestEffective) {
                    $bestEffective = $effective;
                    $bestOrderId   = $orderId;
                }
            }

            if ($bestOrderId === null) break;

            $ordered[]       = $bestOrderId;
            $currentIdx      = $indexMap[$bestOrderId];
            $currentGroupKey = $stops[$bestOrderId]['group_key'] ?? null;
            unset($remaining[$bestOrderId]);
        }

        return $ordered;
    }
}
