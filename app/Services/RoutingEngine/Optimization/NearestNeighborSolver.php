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

                // Group affinity: 40% discount for same group_key.
                // group_key is the first 4 chars of customer name.
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

    /**
     * Hard-group variant: all stops sharing a group_key are visited together
     * before moving on to the next group. Groups are ordered by their nearest
     * stop's distance from depot (index 0). Within each group, stops are
     * sequenced by score-weighted nearest-neighbour. Stops with no group_key
     * are appended at the end ordered by total_score descending.
     */
    public function solveGrouped(array $stops, array $distMatrix, array $indexMap): array
    {
        if (empty($stops)) return [];

        $groups    = [];
        $ungrouped = [];
        foreach ($stops as $orderId => $stop) {
            $key = $stop['group_key'] ?? null;
            if ($key !== null) {
                $groups[$key][$orderId] = $stop;
            } else {
                $ungrouped[$orderId] = $stop;
            }
        }

        // Fall back to regular NN when nothing has a group_key
        if (empty($groups)) {
            return $this->solve([], $stops, $distMatrix, $indexMap, false);
        }

        // Order groups: nearest stop in each group to depot (index 0)
        $groupDist = [];
        foreach ($groups as $key => $groupStops) {
            $minDist = PHP_FLOAT_MAX;
            foreach ($groupStops as $orderId => $stop) {
                $idx  = $indexMap[$orderId] ?? null;
                $dist = ($idx !== null) ? ($distMatrix[0][$idx]['distance_m'] ?? PHP_FLOAT_MAX) : PHP_FLOAT_MAX;
                if ($dist < $minDist) $minDist = $dist;
            }
            $groupDist[$key] = $minDist;
        }
        asort($groupDist);

        $ordered    = [];
        $currentIdx = 0;

        foreach (array_keys($groupDist) as $key) {
            $remaining = $groups[$key];

            while (!empty($remaining)) {
                $bestId        = null;
                $bestEffective = PHP_FLOAT_MAX;

                foreach ($remaining as $orderId => $stop) {
                    $stopIdx = $indexMap[$orderId] ?? null;
                    if ($stopIdx === null) continue;

                    $raw        = $distMatrix[$currentIdx][$stopIdx]['distance_m'] ?? PHP_INT_MAX;
                    $scoreBoost = ($stop['total_score'] ?? 0) / 100.0;
                    $effective  = $raw / (1 + $scoreBoost);

                    if ($effective < $bestEffective) {
                        $bestEffective = $effective;
                        $bestId        = $orderId;
                    }
                }

                if ($bestId === null) break;

                $ordered[]  = $bestId;
                $currentIdx = $indexMap[$bestId];
                unset($remaining[$bestId]);
            }
        }

        // Ungrouped stops: append by score descending
        uasort($ungrouped, fn($a, $b) => ($b['total_score'] ?? 0) <=> ($a['total_score'] ?? 0));
        foreach (array_keys($ungrouped) as $orderId) {
            $ordered[] = $orderId;
        }

        return $ordered;
    }
}
