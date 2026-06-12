<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleGeocodingService
{
    private string $apiKey;
    private int $cacheTtl = 86400; // 24 hours

    public function __construct()
    {
        $this->apiKey = config('services.google.maps_api_key', '');
    }

    public function geocode(string $address): ?array
    {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GOOGLE_MAPS_API_KEY_HERE') {
            return $this->mockGeocode($address);
        }

        $cacheKey = 'geo:' . md5(strtolower(trim($address)));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($address) {
            try {
                $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $address,
                    'region'  => 'id',
                    'key'     => $this->apiKey,
                ]);

                $data = $response->json();

                if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
                    Log::warning('Geocoding failed', ['address' => $address, 'status' => $data['status'] ?? 'unknown']);
                    return null;
                }

                $result = $data['results'][0];
                return [
                    'formatted_address' => $result['formatted_address'],
                    'latitude'          => $result['geometry']['location']['lat'],
                    'longitude'         => $result['geometry']['location']['lng'],
                    'place_id'          => $result['place_id'],
                ];
            } catch (\Exception $e) {
                Log::error('Geocoding exception', ['address' => $address, 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    public function reverseGeocode(float $lat, float $lng): ?string
    {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GOOGLE_MAPS_API_KEY_HERE') {
            return "Jl. Demo {$lat},{$lng}";
        }

        $cacheKey = 'revgeo:' . round($lat, 5) . ':' . round($lng, 5);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($lat, $lng) {
            try {
                $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => "{$lat},{$lng}",
                    'key'    => $this->apiKey,
                ]);

                $data = $response->json();

                if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
                    return null;
                }

                return $data['results'][0]['formatted_address'];
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    private function mockGeocode(string $address): array
    {
        // Bandung area mock coordinates for development without API key
        $bandungPoints = [
            [-6.9175, 107.6191], [-6.9049, 107.6146], [-6.8921, 107.6083],
            [-6.9344, 107.6278], [-6.9222, 107.6312], [-6.8876, 107.6234],
            [-6.9112, 107.6445], [-6.9267, 107.6523], [-6.9389, 107.6418],
            [-6.9001, 107.6567], [-6.8953, 107.6389], [-6.9234, 107.5978],
        ];

        $hash = crc32($address);
        $point = $bandungPoints[abs($hash) % count($bandungPoints)];

        return [
            'formatted_address' => $address . ', Bandung, Jawa Barat',
            'latitude'          => $point[0] + (($hash % 100) / 10000),
            'longitude'         => $point[1] + (($hash % 100) / 10000),
            'place_id'          => 'mock_' . md5($address),
        ];
    }
}
