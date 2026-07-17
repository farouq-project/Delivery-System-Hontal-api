<?php

namespace Tests\Unit\Services;

use App\Services\GoogleMapsLinkService;
use Tests\TestCase;

class GoogleMapsLinkServiceTest extends TestCase
{
    private GoogleMapsLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GoogleMapsLinkService();
    }

    // ─── isGoogleMapsUrl() ────────────────────────────────────────────────────

    public function test_detects_maps_app_goo_gl(): void
    {
        $this->assertTrue($this->service->isGoogleMapsUrl('https://maps.app.goo.gl/abc123'));
    }

    public function test_detects_goo_gl_maps(): void
    {
        $this->assertTrue($this->service->isGoogleMapsUrl('https://goo.gl/maps/xyz'));
    }

    public function test_detects_google_com_maps(): void
    {
        $this->assertTrue($this->service->isGoogleMapsUrl('https://www.google.com/maps/@-6.9175,107.6191,15z'));
    }

    public function test_detects_maps_google_com(): void
    {
        $this->assertTrue($this->service->isGoogleMapsUrl('https://maps.google.com/?q=-6.9175,107.6191'));
    }

    public function test_detects_google_com_maps_place(): void
    {
        $this->assertTrue($this->service->isGoogleMapsUrl('https://www.google.com/maps/place/Bandung/@-6.9175,107.6191,12z'));
    }

    public function test_rejects_non_google_url(): void
    {
        $this->assertFalse($this->service->isGoogleMapsUrl('https://openstreetmap.org/?lat=-6.9175&lon=107.6191'));
    }

    public function test_rejects_random_string(): void
    {
        $this->assertFalse($this->service->isGoogleMapsUrl('not a url'));
    }

    // ─── extractCoordinates() — Pattern 1: /@lat,lng ─────────────────────────

    public function test_extracts_at_pattern(): void
    {
        $url    = 'https://www.google.com/maps/@-6.9175,107.6191,15z';
        $result = $this->service->extractCoordinates($url);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-6.9175, $result['latitude'],  0.0001);
        $this->assertEqualsWithDelta(107.6191, $result['longitude'], 0.0001);
    }

    // ─── extractCoordinates() — Pattern 2: ?q=lat,lng ────────────────────────

    public function test_extracts_q_param_pattern(): void
    {
        $url    = 'https://maps.google.com/?q=-6.9175,107.6191';
        $result = $this->service->extractCoordinates($url);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-6.9175, $result['latitude'],  0.0001);
        $this->assertEqualsWithDelta(107.6191, $result['longitude'], 0.0001);
    }

    // ─── extractCoordinates() — Pattern 3: !3d…!4d ───────────────────────────

    public function test_extracts_3d4d_pattern(): void
    {
        $url    = 'https://www.google.com/maps/place/Name/data=!3d-6.9175!4d107.6191';
        $result = $this->service->extractCoordinates($url);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-6.9175, $result['latitude'],  0.0001);
        $this->assertEqualsWithDelta(107.6191, $result['longitude'], 0.0001);
    }

    // ─── extractCoordinates() — Pattern 4: ll= ───────────────────────────────

    public function test_extracts_ll_pattern(): void
    {
        $url    = 'https://maps.google.com/?ll=-6.9175,107.6191&z=15';
        $result = $this->service->extractCoordinates($url);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-6.9175, $result['latitude'],  0.0001);
        $this->assertEqualsWithDelta(107.6191, $result['longitude'], 0.0001);
    }

    // ─── extractCoordinates() — Pattern 5: sll= ──────────────────────────────

    public function test_extracts_sll_pattern(): void
    {
        $url    = 'https://maps.google.com/?sll=-6.9175,107.6191';
        $result = $this->service->extractCoordinates($url);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-6.9175, $result['latitude'],  0.0001);
        $this->assertEqualsWithDelta(107.6191, $result['longitude'], 0.0001);
    }

    // ─── extractCoordinates() — Pattern 6: center= ───────────────────────────

    public function test_extracts_center_pattern(): void
    {
        $url    = 'https://www.google.com/maps?center=-6.9175,107.6191&zoom=15';
        $result = $this->service->extractCoordinates($url);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-6.9175, $result['latitude'],  0.0001);
        $this->assertEqualsWithDelta(107.6191, $result['longitude'], 0.0001);
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public function test_rejects_zero_zero_coordinates(): void
    {
        $url    = 'https://www.google.com/maps/@0.0,0.0,2z';
        $result = $this->service->extractCoordinates($url);

        $this->assertNull($result);
    }

    public function test_rejects_out_of_range_latitude(): void
    {
        $url    = 'https://www.google.com/maps/@99.0,107.6191,15z';
        $result = $this->service->extractCoordinates($url);

        $this->assertNull($result);
    }

    public function test_rejects_out_of_range_longitude(): void
    {
        $url    = 'https://www.google.com/maps/@-6.9175,200.0,15z';
        $result = $this->service->extractCoordinates($url);

        $this->assertNull($result);
    }

    public function test_returns_null_for_url_with_no_coordinates(): void
    {
        $url    = 'https://www.google.com/maps/place/BandungCity';
        $result = $this->service->extractCoordinates($url);

        $this->assertNull($result);
    }

    // ─── Place URL with both @ and !3d patterns ───────────────────────────────

    public function test_at_pattern_takes_priority_over_3d_pattern(): void
    {
        // URL has both patterns — @ coordinates come first and should match first
        $url    = 'https://www.google.com/maps/place/Name/@-6.9175,107.6191,15z/data=!3d-6.9000!4d107.6000';
        $result = $this->service->extractCoordinates($url);

        $this->assertNotNull($result);
        // Pattern 1 (/@) hits first
        $this->assertEqualsWithDelta(-6.9175, $result['latitude'],  0.0001);
        $this->assertEqualsWithDelta(107.6191, $result['longitude'], 0.0001);
    }
}
