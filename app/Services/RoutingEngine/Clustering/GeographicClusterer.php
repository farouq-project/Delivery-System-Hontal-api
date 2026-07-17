<?php

namespace App\Services\RoutingEngine\Clustering;

class GeographicClusterer
{
    public function __construct(private float $resolution = 0.01) {}

    /**
     * Assign each stop a grid-cell key snapped to $resolution degrees (~1.1 km per cell).
     *
     * @param  array  $stops  [id => ['lat' => float, 'lng' => float, ...]]
     * @return array          [id => 'cellKey']
     */
    public function clusterStops(array $stops): array
    {
        $result = [];
        foreach ($stops as $id => $stop) {
            if (isset($stop['lat'], $stop['lng'])) {
                $cellLat = floor($stop['lat'] / $this->resolution) * $this->resolution;
                $cellLng = floor($stop['lng'] / $this->resolution) * $this->resolution;
                $result[$id] = sprintf('%.4f_%.4f', $cellLat, $cellLng);
            }
        }
        return $result;
    }

    /**
     * Compute the geographic centroid of each cluster.
     *
     * @return array  [cellKey => ['lat' => float, 'lng' => float]]
     */
    public function clusterCentroids(array $stops, array $clusterMap): array
    {
        $buckets = [];
        foreach ($clusterMap as $id => $cell) {
            if (isset($stops[$id])) {
                $buckets[$cell][] = $stops[$id];
            }
        }

        $centroids = [];
        foreach ($buckets as $cell => $pts) {
            $centroids[$cell] = [
                'lat' => array_sum(array_column($pts, 'lat')) / count($pts),
                'lng' => array_sum(array_column($pts, 'lng')) / count($pts),
            ];
        }

        return $centroids;
    }
}
