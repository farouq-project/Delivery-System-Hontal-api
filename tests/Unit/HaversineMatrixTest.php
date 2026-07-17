<?php

namespace Tests\Unit;

use App\Services\RoutingEngine\Preprocessing\HaversineMatrix;
use PHPUnit\Framework\TestCase;

class HaversineMatrixTest extends TestCase
{
    private HaversineMatrix $matrix;

    protected function setUp(): void
    {
        $this->matrix = new HaversineMatrix(25.0);
    }

    public function test_diagonal_is_zero(): void
    {
        $points = [
            ['lat' => -6.90, 'lng' => 107.60],
            ['lat' => -6.91, 'lng' => 107.61],
        ];

        $result = $this->matrix->build($points);

        $this->assertEquals(0.0, $result[0][0]['distance_m']);
        $this->assertEquals(0.0, $result[1][1]['distance_m']);
        $this->assertEquals(0.0, $result[0][0]['duration_min']);
    }

    public function test_distance_is_symmetric(): void
    {
        $points = [
            ['lat' => -6.90, 'lng' => 107.60],
            ['lat' => -6.95, 'lng' => 107.65],
        ];

        $result = $this->matrix->build($points);

        $this->assertEqualsWithDelta($result[0][1]['distance_m'], $result[1][0]['distance_m'], 1.0);
    }

    public function test_known_distance_is_approximately_correct(): void
    {
        // Bandung city center ≈ 7.6 km from test depot
        $points = [
            ['lat' => -6.9175, 'lng' => 107.6191], // depot
            ['lat' => -6.9824, 'lng' => 107.6679], // destination ~7.6 km away
        ];

        $result = $this->matrix->build($points);

        $this->assertGreaterThan(7000, $result[0][1]['distance_m']);
        $this->assertLessThan(9000, $result[0][1]['distance_m']);
    }

    public function test_duration_is_distance_over_speed(): void
    {
        $points = [
            ['lat' => -6.90, 'lng' => 107.60],
            ['lat' => -6.91, 'lng' => 107.61],
        ];

        $result = $this->matrix->build($points);

        $distM     = $result[0][1]['distance_m'];
        $expectedM = ($distM / 1000) / 25.0 * 60;

        $this->assertEqualsWithDelta($expectedM, $result[0][1]['duration_min'], 0.01);
    }

    public function test_single_point_matrix(): void
    {
        $points = [['lat' => -6.90, 'lng' => 107.60]];
        $result = $this->matrix->build($points);

        $this->assertCount(1, $result);
        $this->assertEquals(0.0, $result[0][0]['distance_m']);
    }

    public function test_matrix_dimensions_match_point_count(): void
    {
        $points = array_fill(0, 5, ['lat' => -6.90, 'lng' => 107.60]);
        $result = $this->matrix->build($points);

        $this->assertCount(5, $result);
        foreach ($result as $row) {
            $this->assertCount(5, $row);
        }
    }
}
