<?php

namespace App\Services;

/**
 * Pure math helpers for geographic calculations.
 * No database, no HTTP — safe to call anywhere.
 */
class LocationService
{
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Haversine distance between two coordinate pairs, in kilometres.
     */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Initial bearing from point A to point B, in degrees (0–360, clockwise from North).
     */
    public static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $dLon = deg2rad($lon2 - $lon1);

        $y = sin($dLon) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * True if the point (lat, lon) lies within $radiusKm of the centre.
     */
    public static function withinRadius(
        float $lat,
        float $lon,
        float $centerLat,
        float $centerLon,
        float $radiusKm
    ): bool {
        return self::distance($lat, $lon, $centerLat, $centerLon) <= $radiusKm;
    }

    /**
     * Returns a deterministic grid-cell key for future auto-clustering.
     * Precision 2 → ~1.1 km cells; precision 3 → ~111 m cells.
     * No UI surfaces this yet — prepared for Phase 6.
     */
    public static function futureClusterKey(float $lat, float $lon, int $precision = 2): string
    {
        $factor = 10 ** $precision;
        $gridLat = floor($lat * $factor) / $factor;
        $gridLon = floor($lon * $factor) / $factor;

        return "lat:{$gridLat}_lon:{$gridLon}";
    }

    // ─── Driver GPS Correction Contracts (Phase 6 preparation) ───────────────
    //
    // Intended workflow:
    //   1. Driver marks a delivery location as "pin looks wrong" in the driver app.
    //   2. Their current GPS position is captured and sent to the backend.
    //   3. Backend calls prepareLocationCorrection() to build a structured proposal.
    //   4. Proposal is stored and surfaced to the dispatcher (no routes/controllers yet).
    //   5. Dispatcher accepts (acceptCorrection) or rejects (rejectCorrection).
    //   6. Accepted corrections update customer.default_latitude/longitude.
    //
    // These are pure contracts — no persistence, no side effects. Phase 6 wires
    // them into controllers and triggers customer record updates.

    /**
     * Builds a structured proposal from a driver's reported position vs. the
     * stored customer coordinates. Callers decide whether to persist or discard.
     *
     * @param float $reportedLat  GPS latitude reported by the driver
     * @param float $reportedLng  GPS longitude reported by the driver
     * @param float $currentLat   Current latitude stored on the customer record
     * @param float $currentLng   Current longitude stored on the customer record
     */
    public static function prepareLocationCorrection(
        float $reportedLat,
        float $reportedLng,
        float $currentLat,
        float $currentLng,
    ): array {
        $distance = self::distance($reportedLat, $reportedLng, $currentLat, $currentLng);
        $bearing  = self::bearing($currentLat, $currentLng, $reportedLat, $reportedLng);

        return [
            'reported_lat'   => $reportedLat,
            'reported_lng'   => $reportedLng,
            'current_lat'    => $currentLat,
            'current_lng'    => $currentLng,
            'distance_km'    => round($distance, 3),
            'bearing_deg'    => round($bearing, 1),
            'is_significant' => $distance >= 0.1, // corrections < 100 m are noise
        ];
    }

    /**
     * Accepts a correction proposal.
     * Returns the merged coordinate set — persistence is the caller's responsibility.
     */
    public static function acceptCorrection(array $proposal): array
    {
        return [
            'latitude'    => $proposal['reported_lat'],
            'longitude'   => $proposal['reported_lng'],
            'distance_km' => $proposal['distance_km'],
        ];
    }

    /**
     * Rejects a correction proposal.
     * Returns a structured rejection record — logging is the caller's responsibility.
     */
    public static function rejectCorrection(array $proposal, string $reason = ''): array
    {
        return [
            'kept_lat'  => $proposal['current_lat'],
            'kept_lng'  => $proposal['current_lng'],
            'reason'    => $reason,
        ];
    }
}
