<?php

/**
 * Class Geo
 */
class Geo 
{
    /**
     * Earth radius (meters)
     */
    const EARTH_RADIUS = 6378137;

    /**
     * Calculate bounding rect and return SW & NR points
     *
     * @param LatLng $center Center point
     * @param int $radius Radius (meters)
     * @return LatLng[]
     */
    public static function getBounds(LatLng $center, $radius)
    {
        $deg_0 = $center->destination(0, $radius);
        $deg_90 = $center->destination(90, $radius);
        $deg_180 = $center->destination(180, $radius);
        $deg_270 = $center->destination(270, $radius);

        return array( new LatLng($deg_180->lat, $deg_270->lng), new LatLng($deg_0->lat, $deg_90->lng) );
    }

    /**
     * Return distance between 2 point in meters
     *
     * @param LatLng $location1 First location
     * @param LatLng $location2 Second location
     * @return int
     */
    public static function getDistance(LatLng $location1, LatLng $location2)
    {
        $lat1 = deg2rad($location1->lat);
        $lng1 = deg2rad($location1->lng);
        $lat2 = deg2rad($location2->lat);
        $lng2 = deg2rad($location2->lng);

        $distance = acos(sin($lat1) * sin($lat2) + cos($lat1) * cos($lat2) * cos($lng2 - $lng1)) * self::EARTH_RADIUS;
        return (int)round($distance);
    }

    /**
     * Return heading
     *
     * @param LatLng $locationFrom Starting point
     * @param LatLng $locationTo Destination
     * @return float
     */
    public static function getHeading(LatLng $locationFrom, LatLng $locationTo)
    {
        $wrapLongitude = function($lng)
        {
            return fmod((fmod(($lng - -180), 360) + 360), 360) + -180;
        };

        $fromLat = deg2rad($locationFrom->lat);
        $toLat = deg2rad($locationTo->lat);
        $lng = deg2rad($locationTo->lng) - deg2rad($locationFrom->lng);

        return $wrapLongitude(rad2deg(atan2(sin($lng) * cos($toLat), cos($fromLat) * sin($toLat) - sin($fromLat) * cos($toLat) * cos($lng))));
    }

    /**
     * Return new location offset
     *
     * @param LatLng $locationFrom Starting point
     * @param LatLng $locationTo Destination
     * @param float $distance Distance (meters)
     * @return LatLng
     */
    public static function offsetDistance(LatLng $locationFrom, LatLng $locationTo, $distance)
    {
        $distance = (float)$distance / self::EARTH_RADIUS;
        $heading = self::getHeading($locationFrom, $locationTo);

        $heading = deg2rad($heading);
        $fromLat = deg2rad($locationFrom->lat);
        $cosDistance = cos($distance);
        $sinDistance = sin($distance);
        $sinFromLat = sin($fromLat);
        $cosFromLat = cos($fromLat);
        $sc = $cosDistance * $sinFromLat + $sinDistance * $cosFromLat * cos($heading);

        $lat = rad2deg(asin($sc));
        $lng = rad2deg(deg2rad($locationFrom->lng) + atan2($sinDistance * $cosFromLat * sin($heading), $cosDistance - $sinFromLat * $sc));

        return new LatLng($lat, $lng);
    }
}