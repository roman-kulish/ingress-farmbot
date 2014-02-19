<?php

/**
 * Class LatLng
 */
class LatLng 
{
    /**
     * Latitude
     *
     * @var float
     */
    public $lat = null;

    /**
     * Longitude
     *
     * @var float
     */
    public $lng = null;

    /**
     * Constructor
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     */
    public function __construct($lat, $lng)
    {
        $this->lat = $lat;
        $this->lng = $lng;
    }

    /**
     * Calculate destination point
     *
     * @param float $bearing Bearing
     * @param int $distance Distance to the point
     * @return LatLng
     */
    public function destination($bearing, $distance)
    {
        $distance = (int)$distance / Geo::EARTH_RADIUS;
        $bearing = deg2rad( (float)$bearing );

        $rlat = deg2rad($this->lat);
        $rlng = deg2rad($this->lng);

        $lat = asin(sin($rlat) * cos($distance) + cos($rlat) * sin($distance) * cos($bearing));
        $lng = $rlng + atan2(sin($bearing) * sin($distance) * cos($rlat), cos($distance) - sin($rlat) * sin($lat));

        return new LatLng( rad2deg($lat), rad2deg($lng) );
    }

    /**
     * Return latitude/longitude as string
     *
     * @return string
     */
    public function toString()
    {
        return sprintf('%0.6f,%0.6f', $this->lat, $this->lng);
    }

    /**
     * Return latitude/longitude as E6 format string
     *
     * @return string
     */
    public function toE6String()
    {
        return sprintf('%08x,%08x', (int)($this->lat * 1e6), (int)($this->lng * 1e6));
    }
}