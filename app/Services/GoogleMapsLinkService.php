<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class GoogleMapsLinkService
{
    /**
     * Returns true if the URL looks like any supported Google Maps URL.
     * Covers short links (goo.gl), mobile share links (maps.app.goo.gl),
     * and all google.com/maps variants including place, directions, and search.
     */
    public function isGoogleMapsUrl(string $url): bool
    {
        return str_contains($url, 'maps.app.goo.gl')
            || str_contains($url, 'goo.gl/maps')
            || str_contains($url, 'maps.google.com')
            || str_contains($url, 'google.com/maps')
            || str_contains($url, 'google.co.id/maps')
            || str_contains($url, 'google.co.uk/maps')
            || str_contains($url, 'google.com.au/maps');
    }

    /**
     * Extracts {latitude, longitude} from a Google Maps URL.
     *
     * Strategy:
     * 1. Try all regex patterns directly on the URL as-is (fast path, no network).
     * 2. Only follow HTTP redirects for short URLs (goo.gl) that have no embedded coords.
     *
     * Returns null if no coordinates can be extracted.
     */
    public function extractCoordinates(string $url): ?array
    {
        // Fast path: try all patterns directly — covers full URLs and some short URLs
        $coords = $this->parseCoordinatesFromUrl($url);
        if ($coords) {
            return $coords;
        }

        // Short URLs (goo.gl variants) never contain coordinates themselves.
        // Follow the redirect to obtain the full Google Maps URL, then parse.
        if ($this->isShortUrl($url)) {
            $finalUrl = $this->resolveShortUrl($url);
            if ($finalUrl && $finalUrl !== $url) {
                return $this->parseCoordinatesFromUrl($finalUrl);
            }
        }

        return null;
    }

    /**
     * Attempts to extract latitude/longitude using multiple URL patterns.
     * Patterns are ordered from most-specific to least-specific.
     */
    private function parseCoordinatesFromUrl(string $url): ?array
    {
        // Pattern 1: /@lat,lng,zoom — standard embedded view coordinates
        // e.g. /maps/@-6.9175,107.6191,15z
        if (preg_match('/@(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/', $url, $m)) {
            return $this->validated($m[1], $m[2]);
        }

        // Pattern 2: !3d<lat>!4d<lng> — encoded Place data markers
        // e.g. /maps/place/...!3d-6.9175!4d107.6191
        if (preg_match('/!3d(-?\d{1,3}\.\d+).*?!4d(-?\d{1,3}\.\d+)/', $url, $m)) {
            return $this->validated($m[1], $m[2]);
        }

        // Pattern 3: ?q=lat,lng or &q=lat,lng — plain coordinate query
        // e.g. maps.google.com/?q=-6.9175,107.6191
        if (preg_match('/[?&]q=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/', $url, $m)) {
            return $this->validated($m[1], $m[2]);
        }

        // Pattern 4: ll=lat,lng — older Google Maps parameter
        if (preg_match('/[?&]ll=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/', $url, $m)) {
            return $this->validated($m[1], $m[2]);
        }

        // Pattern 5: sll=lat,lng — "start location lat/lng" used in some shared links
        if (preg_match('/[?&]sll=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/', $url, $m)) {
            return $this->validated($m[1], $m[2]);
        }

        // Pattern 6: center=lat,lng — used in some embed/share URLs
        if (preg_match('/[?&]center=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/', $url, $m)) {
            return $this->validated($m[1], $m[2]);
        }

        return null;
    }

    /**
     * True only for short URLs that require a redirect to obtain coordinates.
     */
    private function isShortUrl(string $url): bool
    {
        return str_contains($url, 'goo.gl/maps')
            || str_contains($url, 'maps.app.goo.gl');
    }

    /**
     * Follows HTTP redirects via a cURL HEAD request and returns the effective URL.
     * Returns null on cURL error, timeout, or if cURL is not available.
     */
    private function resolveShortUrl(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            Log::warning('GoogleMapsLinkService: cURL not available, cannot resolve short URL', ['url' => $url]);
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOBODY         => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'HontalBot/1.0',
        ]);

        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error    = curl_error($ch);

        if ($error) {
            Log::warning('GoogleMapsLinkService: cURL error resolving short URL', [
                'url'   => $url,
                'error' => $error,
            ]);
            return null;
        }

        return $finalUrl ?: null;
    }

    /**
     * Validates parsed coordinate strings and returns the result array.
     * Rejects coordinates outside valid ranges and the exact (0, 0) point
     * which is almost always a parsing artifact rather than a real location.
     */
    private function validated(string $lat, string $lng): ?array
    {
        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        return ['latitude' => $lat, 'longitude' => $lng];
    }
}
