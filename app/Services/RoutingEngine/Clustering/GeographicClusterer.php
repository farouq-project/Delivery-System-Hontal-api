<?php

namespace App\Services\RoutingEngine\Clustering;

class GeographicClusterer
{
    private int $maxIterations = 50;
    private float $convergenceThreshold = 0.0001;

    /**
     * Cluster orders into K groups using K-means on lat/lng.
     *
     * @param array $orders  [id => ['lat' => float, 'lng' => float]]
     * @param int   $k       Number of clusters
     * @return array         [cluster_index => [order_id, ...]]
     */
    public function cluster(array $orders, int $k): array
    {
        if (empty($orders)) return [];
        $k = min($k, count($orders));
        if ($k === 1) return [array_keys($orders)];

        $ids = array_keys($orders);
        $points = array_values($orders);

        // K-means++ initialization
        $centroids = $this->initCentroids($points, $k);

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $assignments = $this->assignToCentroids($points, $centroids);
            $newCentroids = $this->recomputeCentroids($points, $assignments, $k);

            if ($this->hasConverged($centroids, $newCentroids)) {
                break;
            }
            $centroids = $newCentroids;
        }

        $assignments = $this->assignToCentroids($points, $centroids);
        $clusters = array_fill(0, $k, []);
        foreach ($assignments as $pointIdx => $clusterIdx) {
            $clusters[$clusterIdx][] = $ids[$pointIdx];
        }

        // Remove empty clusters
        return array_values(array_filter($clusters));
    }

    private function initCentroids(array $points, int $k): array
    {
        $centroids = [];
        $n = count($points);

        // Pick first centroid randomly
        $centroids[] = $points[array_rand($points)];

        for ($i = 1; $i < $k; $i++) {
            // Compute squared distances to nearest centroid
            $distances = [];
            foreach ($points as $point) {
                $minDist = PHP_FLOAT_MAX;
                foreach ($centroids as $centroid) {
                    $dist = $this->haversine($point, $centroid);
                    $minDist = min($minDist, $dist * $dist);
                }
                $distances[] = $minDist;
            }

            // Choose next centroid with probability proportional to distance
            $total = array_sum($distances);
            $rand = mt_rand() / mt_getrandmax() * $total;
            $cumulative = 0;
            $chosenIdx = 0;
            foreach ($distances as $idx => $dist) {
                $cumulative += $dist;
                if ($cumulative >= $rand) {
                    $chosenIdx = $idx;
                    break;
                }
            }
            $centroids[] = $points[$chosenIdx];
        }

        return $centroids;
    }

    private function assignToCentroids(array $points, array $centroids): array
    {
        $assignments = [];
        foreach ($points as $idx => $point) {
            $minDist = PHP_FLOAT_MAX;
            $bestCluster = 0;
            foreach ($centroids as $ci => $centroid) {
                $dist = $this->haversine($point, $centroid);
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $bestCluster = $ci;
                }
            }
            $assignments[$idx] = $bestCluster;
        }
        return $assignments;
    }

    private function recomputeCentroids(array $points, array $assignments, int $k): array
    {
        $sums = array_fill(0, $k, ['lat' => 0, 'lng' => 0, 'count' => 0]);
        foreach ($assignments as $pointIdx => $clusterIdx) {
            $sums[$clusterIdx]['lat'] += $points[$pointIdx]['lat'];
            $sums[$clusterIdx]['lng'] += $points[$pointIdx]['lng'];
            $sums[$clusterIdx]['count']++;
        }

        $centroids = [];
        foreach ($sums as $ci => $sum) {
            if ($sum['count'] > 0) {
                $centroids[$ci] = ['lat' => $sum['lat'] / $sum['count'], 'lng' => $sum['lng'] / $sum['count']];
            } else {
                $centroids[$ci] = ['lat' => 0, 'lng' => 0];
            }
        }

        return $centroids;
    }

    private function hasConverged(array $old, array $new): bool
    {
        foreach ($old as $i => $centroid) {
            if (!isset($new[$i])) return false;
            if (abs($centroid['lat'] - $new[$i]['lat']) > $this->convergenceThreshold) return false;
            if (abs($centroid['lng'] - $new[$i]['lng']) > $this->convergenceThreshold) return false;
        }
        return true;
    }

    private function haversine(array $from, array $to): float
    {
        $R = 6371;
        $dLat = deg2rad($to['lat'] - $from['lat']);
        $dLng = deg2rad($to['lng'] - $from['lng']);
        $a = sin($dLat/2)**2 + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat'])) * sin($dLng/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
