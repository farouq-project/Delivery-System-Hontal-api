<?php

namespace App\Services\DistanceMatrix;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDistanceMatrixService
{
    private string $apiKey;
    private int $cacheTtl = 600; // 10 minutes
    private int $maxElements = 25; // per request

    public function __construct()
    {
        $this->apiKey = config('services.google.maps_api_key', '');
    }

    /**
     * Get distance matrix between multiple origins and destinations.
     * Returns array[origin_index][dest_index] = ['distance_m' => int, 'duration_min' => int]
     */
    public function getMatrix(array $origins, array $destinations): array
    {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GOOGLE_MAPS_API_KEY_HERE') {
            return $this->mockMatrix($origins, $destinations);
        }

        $matrix = [];
        $originChunks = array_chunk($origins, $this->maxElements, true);
        $destChunks   = array_chunk($destinations, $this->maxElements, true);

        $originOffset = 0;
        foreach ($originChunks as $origChunk) {
            $destOffset = 0;
            foreach ($destChunks as $destChunk) {
                $cacheKey = $this->buildCacheKey($origChunk, $destChunk);
                $chunk = Cache::remember($cacheKey, $this->cacheTtl, function () use ($origChunk, $destChunk) {
                    return $this->fetchChunk(array_values($origChunk), array_values($destChunk));
                });

                foreach ($chunk as $oi => $row) {
                    foreach ($row as $di => $value) {
                        $matrix[$oi + $originOffset][$di + $destOffset] = $value;
                    }
                }
                $destOffset += count($destChunk);
            }
            $originOffset += count($origChunk);
        }

        return $matrix;
    }

    /**
     * Get distance from one point to many destinations.
     */
    public function getDistances(array $origin, array $destinations): array
    {
        $matrix = $this->getMatrix([$origin], $destinations);
        return $matrix[0] ?? [];
    }

    private function fetchChunk(array $origins, array $destinations): array
    {
        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins'      => $this->formatCoords($origins),
                'destinations' => $this->formatCoords($destinations),
                'mode'         => 'driving',
                'units'        => 'metric',
                'key'          => $this->apiKey,
            ]);

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK') {
                Log::warning('Distance Matrix failed', ['status' => $data['status'] ?? 'unknown']);
                return $this->mockMatrix($origins, $destinations);
            }

            $matrix = [];
            foreach ($data['rows'] as $oi => $row) {
                foreach ($row['elements'] as $di => $element) {
                    if ($element['status'] === 'OK') {
                        $matrix[$oi][$di] = [
                            'distance_m'   => $element['distance']['value'],
                            'duration_min' => (int) ceil($element['duration']['value'] / 60),
                        ];
                    } else {
                        $matrix[$oi][$di] = $this->haversineDistance($origins[$oi], $destinations[$di]);
                    }
                }
            }

            return $matrix;
        } catch (\Exception $e) {
            Log::error('Distance Matrix exception', ['error' => $e->getMessage()]);
            return $this->mockMatrix($origins, $destinations);
        }
    }

    private function formatCoords(array $points): string
    {
        return implode('|', array_map(
            fn($p) => round($p['lat'], 7) . ',' . round($p['lng'], 7),
            $points
        ));
    }

    private function buildCacheKey(array $origins, array $destinations): string
    {
        $o = array_map(fn($p) => round($p['lat'], 4) . ',' . round($p['lng'], 4), $origins);
        $d = array_map(fn($p) => round($p['lat'], 4) . ',' . round($p['lng'], 4), $destinations);
        return 'dm:' . md5(implode('|', $o) . '::' . implode('|', $d));
    }

    private function haversineDistance(array $from, array $to): array
    {
        $R = 6371000;
        $lat1 = deg2rad($from['lat']);
        $lat2 = deg2rad($to['lat']);
        $dLat = deg2rad($to['lat'] - $from['lat']);
        $dLng = deg2rad($to['lng'] - $from['lng']);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distM = (int) ($R * $c);

        return [
            'distance_m'   => $distM,
            'duration_min' => (int) ceil($distM / 1000 / 25 * 60), // 25 km/h urban
        ];
    }

    private function mockMatrix(array $origins, array $destinations): array
    {
        $matrix = [];
        foreach ($origins as $oi => $origin) {
            foreach ($destinations as $di => $dest) {
                $matrix[$oi][$di] = $this->haversineDistance($origin, $dest);
            }
        }
        return $matrix;
    }
}
