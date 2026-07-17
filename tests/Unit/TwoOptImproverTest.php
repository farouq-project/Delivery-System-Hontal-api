<?php

namespace Tests\Unit;

use App\Services\RoutingEngine\Optimization\TwoOptImprover;
use PHPUnit\Framework\TestCase;

class TwoOptImproverTest extends TestCase
{
    private TwoOptImprover $improver;

    protected function setUp(): void
    {
        $this->improver = new TwoOptImprover();
    }

    private function buildMatrix(array $points): array
    {
        $n      = count($points);
        $matrix = [];
        $R      = 6371000;

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    $matrix[$i][$j] = ['distance_m' => 0];
                    continue;
                }
                $dLat = deg2rad($points[$j]['lat'] - $points[$i]['lat']);
                $dLng = deg2rad($points[$j]['lng'] - $points[$i]['lng']);
                $a    = sin($dLat / 2) ** 2 + cos(deg2rad($points[$i]['lat'])) * cos(deg2rad($points[$j]['lat'])) * sin($dLng / 2) ** 2;
                $matrix[$i][$j] = ['distance_m' => $R * 2 * atan2(sqrt($a), sqrt(1 - $a))];
            }
        }

        return $matrix;
    }

    public function test_improves_crossing_route(): void
    {
        // 4 points in a cross pattern — 2-opt should un-cross them
        $points = [
            ['lat' => -6.90, 'lng' => 107.60], // depot (index 0)
            ['lat' => -6.91, 'lng' => 107.61], // stop 1
            ['lat' => -6.93, 'lng' => 107.63], // stop 2 (far)
            ['lat' => -6.91, 'lng' => 107.63], // stop 3 (crossed)
            ['lat' => -6.93, 'lng' => 107.61], // stop 4 (crossed)
        ];

        $indexMap  = [101 => 1, 102 => 2, 103 => 3, 104 => 4];
        $matrix    = $this->buildMatrix($points);
        $routeBad  = [101, 103, 104, 102]; // crosses

        $improved = $this->improver->improve($routeBad, $matrix, $indexMap);

        // Compute route distances
        $distBad  = $this->routeDistance($routeBad, $indexMap, $matrix);
        $distGood = $this->routeDistance($improved, $indexMap, $matrix);

        $this->assertLessThanOrEqual($distBad, $distGood);
    }

    public function test_no_change_on_optimal_route(): void
    {
        $points = [
            ['lat' => -6.90, 'lng' => 107.60],
            ['lat' => -6.91, 'lng' => 107.60],
            ['lat' => -6.91, 'lng' => 107.61],
            ['lat' => -6.92, 'lng' => 107.61],
            ['lat' => -6.92, 'lng' => 107.62],
        ];

        $indexMap = [1 => 1, 2 => 2, 3 => 3, 4 => 4];
        $matrix   = $this->buildMatrix($points);
        $route    = [1, 2, 3, 4];

        $improved = $this->improver->improve($route, $matrix, $indexMap);

        // Route distance should not increase
        $this->assertLessThanOrEqual(
            $this->routeDistance($route, $indexMap, $matrix),
            $this->routeDistance($improved, $indexMap, $matrix)
        );
    }

    public function test_short_route_returned_unchanged(): void
    {
        $matrix   = [[['distance_m' => 0]]];
        $indexMap = [1 => 0];
        $route    = [1, 2, 3]; // < 4 stops

        $result = $this->improver->improve($route, $matrix, $indexMap);

        $this->assertSame($route, $result);
    }

    public function test_locked_stops_not_moved(): void
    {
        $points = [
            ['lat' => -6.90, 'lng' => 107.60],
            ['lat' => -6.91, 'lng' => 107.61],
            ['lat' => -6.93, 'lng' => 107.63],
            ['lat' => -6.91, 'lng' => 107.63],
            ['lat' => -6.93, 'lng' => 107.61],
        ];

        $indexMap = [101 => 1, 102 => 2, 103 => 3, 104 => 4];
        $matrix   = $this->buildMatrix($points);
        $route    = [101, 103, 104, 102];
        $locked   = [103 => true]; // lock stop 103

        $improved = $this->improver->improve($route, $matrix, $indexMap, $locked);

        $this->assertContains(103, $improved);
    }

    private function routeDistance(array $route, array $indexMap, array $matrix): float
    {
        $total   = 0;
        $prevIdx = 0;
        foreach ($route as $id) {
            $idx     = $indexMap[$id] ?? null;
            if ($idx === null) continue;
            $total  += $matrix[$prevIdx][$idx]['distance_m'] ?? 0;
            $prevIdx = $idx;
        }
        return $total;
    }
}
