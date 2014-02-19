<?php

/**
 * Class Util
 */
class Util 
{
    /**
     * Return level for given AP
     *
     * @param int $ap AP
     * @return int
     */
    public static function levelForAp($ap)
    {
        settype($ap, 'integer');

        switch(true) {
            case $ap >= 1200000:
                return 8;

            case $ap >= 600000:
                return 7;

            case $ap >= 300000:
                return 6;

            case $ap >= 150000:
                return 5;

            case $ap >= 70000:
                return 4;

            case $ap >= 30000:
                return 3;

            case $ap >= 10000:
                return 2;

            case $ap >= 0:
                return 1;

            default:
                return 0;
        }
    }

    /**
     * Return maximum XM for given level
     *
     * @param int $level Level
     * @return int
     */
    public static function maxXMForLevel($level)
    {
        settype($level, 'integer');

        $xm = array(
            1 => 3000,
            2 => 4000,
            3 => 5000,
            4 => 6000,
            5 => 7000,
            6 => 8000,
            7 => 9000,
            8 => 10000
        );

        return ( isset($xm[$level]) ? $xm[$level] : 0 );
    }

    /**
     * Return faction name from Id
     *
     * @param string $factionId Faction Id
     * @return string
     */
    public static function faction($factionId)
    {
        switch($factionId) {
            case 'ALIENS':
                return 'Enlightened';

            case 'RESISTANCE':
                return 'Resistance';

            default:
                return 'Neutral';
        }
    }

    /**
     * Return rarity string for Id
     *
     * @param string $rarityId Rarity Id
     * @return string
     */
    public static function rarityString($rarityId)
    {
        $rarity = array(
            'VERY_COMMON' => 'Very Common',
            'COMMON'      => 'Common',
            'LESS_COMMON' => 'Less Common',
            'RARE'        => 'Rare',
            'VERY_RARE'   => 'Very Rare',
            'EXTRA_RARE'  => 'Extra Rare'
        );

        return ( isset($rarity[$rarityId]) ? $rarity[$rarityId] : 'Unknown' );
    }

    /**
     * Return item name for Id
     *
     * @param string $itemId Item Id
     * @param int $level Level
     * @param string $rarity Rarity Id
     * @return string
     */
    public static function itemName($itemId, $level, $rarity)
    {
        settype($level, 'integer');

        $item = array(
            'EMITTER_A'       => 'L%d Resonator',
            'EMP_BURSTER'     => 'L%d XMP Burster',
            'MEDIA'           => 'L%d Media',
            'POWER_CUBE'      => 'L%d Power Cube',
            'RES_SHIELD'      => '%s Shield',
            'FORCE_AMP'       => '%s Force Amp',
            'HEATSINK'        => '%s Heat sink',
            'LINK_AMPLIFIER'  => '%s Link Amp',
            'MULTIHACK'       => '%s Multi-hack',
            'TURRET'          => '%s Turret',
            'PORTAL_LINK_KEY' => 'Portal Key',
            'ADA'             => 'ADA Refactor',
            'JARVIS'          => 'Jarvis Virus'
        );

        if ($itemId == 'EMITTER_A' || $itemId == 'EMP_BURSTER' || $itemId == 'MEDIA' || $itemId == 'POWER_CUBE') {
            return sprintf($item[$itemId], $level);
        } else if ($itemId == 'RES_SHIELD' || $itemId == 'FORCE_AMP' || $itemId == 'HEATSINK' || $itemId == 'LINK_AMPLIFIER' || $itemId == 'MULTIHACK' || $itemId == 'TURRET') {
            return sprintf($item[$itemId], self::rarityString($rarity));
        }

        return $item[$itemId];
    }

    /**
     * Format portal name
     *
     * @param string $name Portal name
     * @param float $level Portal level
     * @return string
     */
    public static function portalName($name, $level)
    {
        return sprintf('L%d %s', floor($level), trim($name));
    }
}