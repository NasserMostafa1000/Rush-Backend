<?php

namespace App\Business\Services;

use H3\H3;
use H3\ValueObject\LatLng;

class H3Service
{
    public function resolution(): int
    {
        return max(0, min(15, (int) config('h3.resolution', 8)));
    }

    public function ringK(): int
    {
        return max(0, (int) config('h3.ring_k', 2));
    }

    /**
     * Encode lat/lng to H3 hex index string.
     */
    public function index(float $lat, float $lng): string
    {
        $cell = H3::latLngToCell(new LatLng($lat, $lng), $this->resolution());

        return dechex($cell);
    }

    /**
     * Approximate H3 gridDisk around a point (origin + k rings).
     *
     * @return list<string> hex index strings
     */
    public function disk(float $lat, float $lng, ?int $k = null): array
    {
        $k = $k ?? $this->ringK();
        $cells = [];
        $origin = $this->index($lat, $lng);
        $cells[$origin] = true;

        if ($k <= 0) {
            return array_keys($cells);
        }

        // Average center-to-center distance ? edge * ?3 for hex grid.
        $edgeMeters = $this->approxEdgeLengthMeters($this->resolution());
        $step = $edgeMeters * 1.73205080757;

        for ($ring = 1; $ring <= $k; $ring++) {
            $radius = $ring * $step;
            $samples = max(6 * $ring, 6);
            for ($i = 0; $i < $samples; $i++) {
                $bearing = (360.0 / $samples) * $i;
                [$plat, $plng] = $this->destinationPoint($lat, $lng, $bearing, $radius);
                $cells[$this->index($plat, $plng)] = true;
            }
        }

        return array_keys($cells);
    }

    /**
     * Haversine distance in meters.
     */
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * $earth * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function approxEdgeLengthMeters(int $resolution): float
    {
        // Official average edge lengths (meters) for hexagon cells.
        return match ($resolution) {
            0 => 1107712.591,
            1 => 418676.278,
            2 => 158244.655,
            3 => 59810.866,
            4 => 22606.712,
            5 => 8544.408,
            6 => 3229.569,
            7 => 1220.630,
            8 => 461.355,
            9 => 174.376,
            10 => 65.908,
            11 => 24.911,
            12 => 9.416,
            13 => 3.560,
            14 => 1.349,
            15 => 0.510,
            default => 461.355,
        };
    }

    /**
     * @return array{0: float, 1: float} lat, lng
     */
    private function destinationPoint(float $lat, float $lng, float $bearingDeg, float $distanceM): array
    {
        $earth = 6371000.0;
        $angDist = $distanceM / $earth;
        $bearing = deg2rad($bearingDeg);
        $lat1 = deg2rad($lat);
        $lng1 = deg2rad($lng);

        $sinLat1 = sin($lat1);
        $cosLat1 = cos($lat1);
        $sinAng = sin($angDist);
        $cosAng = cos($angDist);

        $lat2 = asin($sinLat1 * $cosAng + $cosLat1 * $sinAng * cos($bearing));
        $lng2 = $lng1 + atan2(sin($bearing) * $sinAng * $cosLat1, $cosAng - $sinLat1 * sin($lat2));

        return [rad2deg($lat2), rad2deg($lng2)];
    }
}
