<?php

namespace App\Services\RoutingEngine\Optimization;

class TwoOptImprover
{
    private int $maxIterations = 50;
    private float $epsilon = 0.01;

    /**
     * Improve route using 2-opt local search.
     * Skips locked stops.
     *
     * @param array $route       [order_id, ...] ordered
     * @param array $distMatrix  [from_idx => [to_idx => ['distance_m']]]
     * @param array $indexMap    [order_id => matrix_index]
     * @param array $locked      [order_id => true] locked stops skip
     * @return array             Improved route
     */
    public function improve(array $route, array $distMatrix, array $indexMap, array $locked = []): array
    {
        if (count($route) < 4) return $route;

        $improved = true;
        $iterations = 0;

        while ($improved && $iterations < $this->maxIterations) {
            $improved = false;
            $iterations++;

            for ($i = 1; $i < count($route) - 2; $i++) {
                for ($j = $i + 1; $j < count($route); $j++) {
                    // Skip if either endpoint is locked
                    if (!empty($locked[$route[$i]]) || !empty($locked[$route[$j]])) {
                        continue;
                    }

                    $iIdx  = $indexMap[$route[$i]] ?? null;
                    $i1Idx = $indexMap[$route[$i - 1]] ?? null;
                    $jIdx  = $indexMap[$route[$j]] ?? null;
                    $j1Idx = isset($route[$j + 1]) ? ($indexMap[$route[$j + 1]] ?? null) : null;

                    if ($iIdx === null || $i1Idx === null || $jIdx === null) continue;

                    $d1 = ($distMatrix[$i1Idx][$iIdx]['distance_m'] ?? 0)
                        + ($j1Idx !== null ? ($distMatrix[$jIdx][$j1Idx]['distance_m'] ?? 0) : 0);

                    $d2 = ($distMatrix[$i1Idx][$jIdx]['distance_m'] ?? 0)
                        + ($j1Idx !== null ? ($distMatrix[$iIdx][$j1Idx]['distance_m'] ?? 0) : 0);

                    if ($d2 < $d1 - $this->epsilon) {
                        // Reverse the segment [i..j]
                        $segment = array_reverse(array_slice($route, $i, $j - $i + 1));
                        array_splice($route, $i, $j - $i + 1, $segment);
                        $improved = true;
                    }
                }
            }
        }

        return $route;
    }
}
