<?php

namespace App\Services\RoutingEngine\Preprocessing;

class HaversineMatrix
{
    public function __construct(private float $speedKmh = 25.0) {}

    /**
     * Build an NxN distance/duration matrix from an array of lat/lng points.
     * Index 0 is always the origin (depot or driver position).
     *
     * @param  array  $points  [['lat' => float, 'lng' => float], ...]
     * @return array           [from_idx][to_idx] = ['distance_m' => float, 'duration_min' => float]
     */
    public function build(array $points): array
    {
        $n      = count($points);
        $matrix = [];

        for ($i = 0; $i < $n; $i++) {
            $matrix[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    $matrix[$i][$j] = ['distance_m' => 0.0, 'duration_min' => 0.0];
                    continue;
                }
                $distM = $this->haversineM($points[$i], $points[$j]);
                $matrix[$i][$j] = [
                    'distance_m'   => $distM,
                    'duration_min' => ($distM / 1000) / $this->speedKmh * 60,
                ];
            }
        }

        return $matrix;
    }

    private function haversineM(array $from, array $to): float
    {
        $R    = 6371000;
        $dLat = deg2rad($to['lat'] - $from['lat']);
        $dLng = deg2rad($to['lng'] - $from['lng']);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat'])) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
