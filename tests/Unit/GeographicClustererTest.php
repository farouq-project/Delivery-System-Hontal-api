<?php

namespace Tests\Unit;

use App\Services\RoutingEngine\Clustering\GeographicClusterer;
use PHPUnit\Framework\TestCase;

class GeographicClustererTest extends TestCase
{
    private GeographicClusterer $clusterer;

    protected function setUp(): void
    {
        $this->clusterer = new GeographicClusterer(0.01);
    }

    public function test_same_cell_for_nearby_points(): void
    {
        $stops = [
            1 => ['lat' => -6.9001, 'lng' => 107.6001],
            2 => ['lat' => -6.9009, 'lng' => 107.6009],
        ];

        $result = $this->clusterer->clusterStops($stops);

        $this->assertSame($result[1], $result[2]);
    }

    public function test_different_cells_for_distant_points(): void
    {
        $stops = [
            1 => ['lat' => -6.90, 'lng' => 107.60],
            2 => ['lat' => -6.92, 'lng' => 107.62],
        ];

        $result = $this->clusterer->clusterStops($stops);

        $this->assertNotSame($result[1], $result[2]);
    }

    public function test_stops_without_coordinates_are_excluded(): void
    {
        $stops = [
            1 => ['lat' => -6.90, 'lng' => 107.60],
            2 => ['no_coords' => true],
        ];

        $result = $this->clusterer->clusterStops($stops);

        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
    }

    public function test_cell_key_format_is_deterministic(): void
    {
        $stops = [
            1 => ['lat' => -6.915, 'lng' => 107.615],
        ];

        $result = $this->clusterer->clusterStops($stops);

        // Key should be "floor(lat/0.01)*0.01 _ floor(lng/0.01)*0.01"
        $expectedLat = floor(-6.915 / 0.01) * 0.01;
        $expectedLng = floor(107.615 / 0.01) * 0.01;
        $expected = sprintf('%.4f_%.4f', $expectedLat, $expectedLng);

        $this->assertSame($expected, $result[1]);
    }

    public function test_centroid_averages_points_in_cluster(): void
    {
        $stops = [
            1 => ['lat' => -6.900, 'lng' => 107.600],
            2 => ['lat' => -6.902, 'lng' => 107.602],
        ];
        $clusterMap = [1 => 'A', 2 => 'A'];

        $centroids = $this->clusterer->clusterCentroids($stops, $clusterMap);

        $this->assertEqualsWithDelta(-6.901, $centroids['A']['lat'], 0.0001);
        $this->assertEqualsWithDelta(107.601, $centroids['A']['lng'], 0.0001);
    }

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame([], $this->clusterer->clusterStops([]));
        $this->assertSame([], $this->clusterer->clusterCentroids([], []));
    }
}
