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

        $ordered = [];
        $remaining = $stops;
        $currentIdx = 0; // depot/start is index 0

        while (!empty($remaining)) {
            $bestOrderId = null;
            $bestEffective = PHP_FLOAT_MAX;

            foreach ($remaining as $orderId => $stop) {
                $stopIdx = $indexMap[$orderId] ?? null;
                if ($stopIdx === null) continue;

                $raw = $distMatrix[$currentIdx][$stopIdx]['distance_m'] ?? PHP_INT_MAX;
                $scoreBoost = ($stop['total_score'] ?? 0) / 100.0;
                $effective = $raw / (1 + $scoreBoost);

                if ($effective < $bestEffective) {
                    $bestEffective = $effective;
                    $bestOrderId = $orderId;
                }
            }

            if ($bestOrderId === null) break;

            $ordered[] = $bestOrderId;
            $currentIdx = $indexMap[$bestOrderId];
            unset($remaining[$bestOrderId]);
        }

        return $ordered;
    }
}
