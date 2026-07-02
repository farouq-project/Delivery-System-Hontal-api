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
    public function solve(array $start, array $stops, array $distMatrix, array $indexMap): array
    {
        if (empty($stops)) return [];

        $ordered        = [];
        $remaining      = $stops;
        $currentIdx     = 0;   // depot/start is index 0
        $currentCluster = null; // track cluster of last visited stop

        while (!empty($remaining)) {
            $bestOrderId  = null;
            $bestEffective = PHP_FLOAT_MAX;

            foreach ($remaining as $orderId => $stop) {
                $stopIdx = $indexMap[$orderId] ?? null;
                if ($stopIdx === null) continue;

                $raw        = $distMatrix[$currentIdx][$stopIdx]['distance_m'] ?? PHP_INT_MAX;
                $scoreBoost = ($stop['total_score'] ?? 0) / 100.0;
                $effective  = $raw / (1 + $scoreBoost);

                // Same-cluster affinity: give a 40% discount when the candidate
                // is in the same geographic cluster as the current stop.
                // This keeps Jingga stops together, Banyak stops together, etc.
                // 'no cluster' is excluded — only named clusters trigger grouping.
                $stopCluster = $stop['cluster'] ?? null;
                if (
                    $currentCluster &&
                    $stopCluster &&
                    $stopCluster !== 'no cluster' &&
                    $currentCluster === $stopCluster
                ) {
                    $effective *= 0.6;
                }

                if ($effective < $bestEffective) {
                    $bestEffective = $effective;
                    $bestOrderId   = $orderId;
                }
            }

            if ($bestOrderId === null) break;

            $ordered[]      = $bestOrderId;
            $currentIdx     = $indexMap[$bestOrderId];
            $currentCluster = $stops[$bestOrderId]['cluster'] ?? null;
            unset($remaining[$bestOrderId]);
        }

        return $ordered;
    }
}
