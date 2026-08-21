<?php

namespace App\Support;

/**
 * A geo-radius location constraint from the demographics block, of the shape
 * {lat, lon, radius} (radius in metres). This is the single source of truth for
 * recognising that shape - used both when inspecting a query definition and when
 * building the BUNNY GEO_RADIUS rule.
 */
class GeoRadiusLocation
{
    /**
     * A valid geo-radius location is an array carrying numeric lat, lon and
     * radius. Null / empty / legacy region-code shapes are not valid.
     */
    public static function isValid(mixed $location): bool
    {
        return is_array($location)
            && is_numeric($location['lat'] ?? null)
            && is_numeric($location['lon'] ?? null)
            && is_numeric($location['radius'] ?? null);
    }
}
