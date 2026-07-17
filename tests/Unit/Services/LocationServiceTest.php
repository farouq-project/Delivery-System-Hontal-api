<?php

namespace Tests\Unit\Services;

use App\Services\LocationService;
use Tests\TestCase;

class LocationServiceTest extends TestCase
{
    // ─── distance() ───────────────────────────────────────────────────────────

    public function test_distance_between_same_point_is_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, LocationService::distance(-6.9175, 107.6191, -6.9175, 107.6191), 0.001);
    }

    public function test_distance_bandung_to_jakarta_is_approximately_140km(): void
    {
        // Bandung approx -6.92, 107.61 — Jakarta approx -6.20, 106.82
        $km = LocationService::distance(-6.9175, 107.6191, -6.2088, 106.8456);
        $this->assertGreaterThan(120.0, $km);
        $this->assertLessThan(160.0, $km);
    }

    public function test_distance_is_symmetric(): void
    {
        $a = LocationService::distance(-6.9175, 107.6191, -6.2088, 106.8456);
        $b = LocationService::distance(-6.2088, 106.8456, -6.9175, 107.6191);
        $this->assertEqualsWithDelta($a, $b, 0.001);
    }

    // ─── bearing() ────────────────────────────────────────────────────────────

    public function test_bearing_is_within_0_to_360(): void
    {
        $bearing = LocationService::bearing(-6.9175, 107.6191, -6.2088, 106.8456);
        $this->assertGreaterThanOrEqual(0.0, $bearing);
        $this->assertLessThan(360.0, $bearing);
    }

    public function test_bearing_north_is_approximately_0(): void
    {
        // Moving north: lat increases, same lng
        $bearing = LocationService::bearing(0.0, 0.0, 1.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $bearing, 1.0);
    }

    public function test_bearing_east_is_approximately_90(): void
    {
        $bearing = LocationService::bearing(0.0, 0.0, 0.0, 1.0);
        $this->assertEqualsWithDelta(90.0, $bearing, 1.0);
    }

    // ─── withinRadius() ───────────────────────────────────────────────────────

    public function test_within_radius_true_for_nearby_point(): void
    {
        // Same point — always within any positive radius
        $this->assertTrue(LocationService::withinRadius(-6.9175, 107.6191, -6.9175, 107.6191, 1.0));
    }

    public function test_within_radius_false_for_distant_point(): void
    {
        // Bandung → Jakarta is ~140 km — well outside 50 km radius
        $this->assertFalse(LocationService::withinRadius(-6.9175, 107.6191, -6.2088, 106.8456, 50.0));
    }

    public function test_within_radius_respects_boundary(): void
    {
        // ~1.1 km apart
        $distKm = LocationService::distance(-6.9175, 107.6191, -6.9275, 107.6191);
        $this->assertTrue(LocationService::withinRadius(-6.9175, 107.6191, -6.9275, 107.6191, $distKm + 0.1));
        $this->assertFalse(LocationService::withinRadius(-6.9175, 107.6191, -6.9275, 107.6191, $distKm - 0.1));
    }

    // ─── futureClusterKey() ───────────────────────────────────────────────────

    public function test_cluster_key_has_correct_format(): void
    {
        $key = LocationService::futureClusterKey(-6.9175, 107.6191);
        $this->assertMatchesRegularExpression('/^lat:-?\d+\.\d+_lon:-?\d+\.\d+$/', $key);
    }

    public function test_cluster_key_groups_nearby_points(): void
    {
        // Points in the same ~1.1 km cell should produce the same key
        $k1 = LocationService::futureClusterKey(-6.9175, 107.6191);
        $k2 = LocationService::futureClusterKey(-6.9150, 107.6199);
        $this->assertSame($k1, $k2);
    }

    public function test_cluster_key_distinguishes_distant_points(): void
    {
        $k1 = LocationService::futureClusterKey(-6.9175, 107.6191);
        $k2 = LocationService::futureClusterKey(-6.9500, 107.6600);
        $this->assertNotSame($k1, $k2);
    }

    // ─── prepareLocationCorrection() ─────────────────────────────────────────

    public function test_prepare_correction_returns_all_fields(): void
    {
        $proposal = LocationService::prepareLocationCorrection(-6.9275, 107.6291, -6.9175, 107.6191);

        $this->assertArrayHasKey('reported_lat', $proposal);
        $this->assertArrayHasKey('reported_lng', $proposal);
        $this->assertArrayHasKey('current_lat', $proposal);
        $this->assertArrayHasKey('current_lng', $proposal);
        $this->assertArrayHasKey('distance_km', $proposal);
        $this->assertArrayHasKey('bearing_deg', $proposal);
        $this->assertArrayHasKey('is_significant', $proposal);
    }

    public function test_prepare_correction_marks_small_shift_as_not_significant(): void
    {
        // ~10 m shift — below 100 m threshold
        $proposal = LocationService::prepareLocationCorrection(-6.91751, 107.61911, -6.9175, 107.6191);
        $this->assertFalse($proposal['is_significant']);
    }

    public function test_prepare_correction_marks_large_shift_as_significant(): void
    {
        // ~1.1 km shift
        $proposal = LocationService::prepareLocationCorrection(-6.9275, 107.6191, -6.9175, 107.6191);
        $this->assertTrue($proposal['is_significant']);
    }

    // ─── acceptCorrection() ───────────────────────────────────────────────────

    public function test_accept_correction_returns_reported_coordinates(): void
    {
        $proposal = LocationService::prepareLocationCorrection(-6.9275, 107.6291, -6.9175, 107.6191);
        $result   = LocationService::acceptCorrection($proposal);

        $this->assertSame(-6.9275, $result['latitude']);
        $this->assertSame(107.6291, $result['longitude']);
        $this->assertArrayHasKey('distance_km', $result);
    }

    // ─── rejectCorrection() ───────────────────────────────────────────────────

    public function test_reject_correction_preserves_current_coordinates(): void
    {
        $proposal = LocationService::prepareLocationCorrection(-6.9275, 107.6291, -6.9175, 107.6191);
        $result   = LocationService::rejectCorrection($proposal, 'Looks correct on map');

        $this->assertSame(-6.9175, $result['kept_lat']);
        $this->assertSame(107.6191, $result['kept_lng']);
        $this->assertSame('Looks correct on map', $result['reason']);
    }
}
