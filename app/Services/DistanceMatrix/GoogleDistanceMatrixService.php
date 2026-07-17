<?php

namespace App\Services\DistanceMatrix;

use App\Models\GoogleApiUsageLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDistanceMatrixService
{
    private string $apiKey;
    private int    $cacheTtl     = 600; // seconds; overridden by merchant setting
    private int    $maxElements  = 25;  // per API request (Google limit: 25 origins × 25 dests)

    public function __construct()
    {
        $this->apiKey = config('services.google.maps_api_key', '');
    }

    public function setCacheTtl(int $ttl): void
    {
        $this->cacheTtl = $ttl;
    }

    /**
     * Get an NxM distance/duration matrix.
     * Returns array[origin_idx][dest_idx] = ['distance_m' => int, 'duration_min' => int]
     */
    public function getMatrix(array $origins, array $destinations): array
    {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GOOGLE_MAPS_API_KEY_HERE') {
            return $this->mockMatrix($origins, $destinations);
        }

        $matrix       = [];
        $originChunks = array_chunk($origins, $this->maxElements, true);
        $destChunks   = array_chunk($destinations, $this->maxElements, true);

        $originOffset = 0;
        foreach ($originChunks as $origChunk) {
            $destOffset = 0;
            foreach ($destChunks as $destChunk) {
                $cacheKey = $this->buildCacheKey($origChunk, $destChunk);
                $wasCached = Cache::has($cacheKey);

                $chunk = Cache::remember($cacheKey, $this->cacheTtl, function () use ($origChunk, $destChunk) {
                    return $this->fetchChunk(array_values($origChunk), array_values($destChunk));
                });

                $this->logUsage(
                    count($origChunk) * count($destChunk),
                    $wasCached,
                    $cacheKey
                );

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
                Log::warning('[DM] Distance Matrix failed', ['status' => $data['status'] ?? 'unknown']);
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
            Log::error('[DM] Distance Matrix exception', ['error' => $e->getMessage()]);
            return $this->mockMatrix($origins, $destinations);
        }
    }

    private function logUsage(int $elementCount, bool $cacheHit, string $cacheKey): void
    {
        try {
            GoogleApiUsageLog::create([
                'merchant_id'      => null,
                'api_type'         => 'distance_matrix',
                'request_count'    => 1,
                'estimated_units'  => $elementCount,
                'cache_hit'        => $cacheHit,
                'cache_key'        => $cacheKey,
                'response_time_ms' => 0,
            ]);
        } catch (\Throwable) {
            // Non-fatal — logging must never break routing
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
        $R    = 6371000;
        $dLat = deg2rad($to['lat'] - $from['lat']);
        $dLng = deg2rad($to['lng'] - $from['lng']);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat'])) * sin($dLng / 2) ** 2;
        $distM = (int) (6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a)));

        return [
            'distance_m'   => $distM,
            'duration_min' => (int) ceil($distM / 1000 / 25 * 60),
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
