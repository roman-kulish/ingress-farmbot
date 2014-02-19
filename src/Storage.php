<?php

/**
 * Class Storage
 */
class Storage 
{
    /**
     * PDO connection
     *
     * @var PDO
     */
    protected $pdo = null;

    /**
     * Location
     *
     * @var LatLng
     */
    protected $location = null;

    /**
     * Constructor
     *
     * @param string $dbfile Database file
     * @param string $schema Schema SQL file
     */
    public function __construct($dbfile, $schema)
    {
        $this->createConnection($dbfile, $schema);
    }

    /**
     * Adds distance formula for location
     *
     * @param LatLng $location Location
     */
    public function useLocation(LatLng $location)
    {
        $this->pdo->sqliteCreateFunction('distance', function($lat, $lng) use($location) {
            return Geo::getDistance($location, new LatLng($lat, $lng));
        }, 2);
    }

    /**
     * Remove items from inventory
     */
    public function cleanInventory()
    {
        $this->pdo->exec('DELETE FROM inventory');
    }

    /**
     * Update inventory
     *
     * @param array $inventory Inventory data
     * @param bool $listItems whether to print items information or not
     * @throws Exception
     */
    public function updateInventory(array $inventory, $listItems = false)
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT * FROM inventory WHERE guid = ? LIMIT 1');
            $stmtInsert = $this->pdo->prepare('INSERT OR REPLACE INTO inventory (guid, type, timestamp, level, rarity) VALUES (?, ?, ?, ?, ?)');

            for($i = 0, $n = sizeof($inventory); $i < $n; $i++) {
                list($guid, $timestamp, $item) = $inventory[$i];

                $timestamp/= 1000;

                switch(true) {
                    case isset($item->resourceWithLevels):
                        $type = $item->resourceWithLevels->resourceType;
                        break;

                    case isset($item->resource):
                        $type = $item->resource->resourceType;
                        break;

                    case isset($item->modResource):
                        $type = $item->modResource->resourceType;
                        break;

                    default:
                        throw new RuntimeException('Cannot detect item type');
                }

                $stmt->bindValue(1, $guid, PDO::PARAM_STR);
                $stmt->execute();

                if (( $object = $stmt->fetch() ) === false) {
                    $object = (object)array(
                        'guid'      => $guid,
                        'timestamp' => $timestamp,
                        'level'     => null,
                        'rarity'    => null
                    );
                } else {
                    $object->timestamp = $timestamp;
                }

                if ($type == 'EMITTER_A' || $type == 'EMP_BURSTER' || $type == 'MEDIA' || $type == 'POWER_CUBE') {
                    $object->level = $item->resourceWithLevels->level;
                } else if ($type == 'RES_SHIELD' || $type == 'FORCE_AMP' || $type == 'HEATSINK' || $type == 'LINK_AMPLIFIER' || $type == 'MULTIHACK' || $type == 'TURRET') {
                    $object->rarity = $item->modResource->rarity;
                } else if ($type == 'PORTAL_LINK_KEY') {
                    // do nothing
                } else if ($type == 'FLIP_CARD') {
                    $type = $item->flipCard->flipCardType;
                } else {
                    throw new RuntimeException('Unknown item [' . $type . ']');
                }

                $object->type = $type;

                $stmtInsert->bindValue(1, $object->guid, PDO::PARAM_STR);
                $stmtInsert->bindValue(2, $object->type, PDO::PARAM_STR);
                $stmtInsert->bindValue(3, $object->timestamp, PDO::PARAM_INT);
                $stmtInsert->bindValue(4, $object->level, PDO::PARAM_INT);
                $stmtInsert->bindValue(5, $object->rarity, PDO::PARAM_STR);
                $stmtInsert->execute();

                if ($listItems) {
                    printf("  > %s\n", Util::itemName($object->type, $object->level, $object->rarity));
                }
            }

            if ($listItems) {
                printf("\n");
            }

            $this->pdo->commit();
        } catch(Exception $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Update portals
     *
     * @param array $entities Game entities
     * @throws Exception
     */
    public function updatePortals(array $entities)
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT * FROM portals WHERE guid = ? LIMIT 1');
            $stmtInsert = $this->pdo->prepare('INSERT OR REPLACE INTO portals (guid, name, faction, lat, lng, level, timestamp, last_hack_time, burnt_out_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

            for($i = 0, $n = sizeof($entities); $i < $n; $i++) {
                list($guid, $timestamp, $entity) = $entities[$i];

                $timestamp/= 1000;

                if ( isset($entity->portalV2) && isset($entity->locationE6) ) {
                    $stmt->bindValue(1, $guid, PDO::PARAM_STR);
                    $stmt->execute();

                    if (( $object = $stmt->fetch() ) === false) {
                        $object = (object)array(
                            'guid'      => $guid,
                            'timestamp' => $timestamp,
                            'name'      => null,
                            'faction'   => null,
                            'lat'       => null,
                            'lng'       => null,
                            'level'     => null,
                            'last_hack_time' => null,
                            'burnt_out_time' => null
                        );
                    } else {
                        $object->timestamp = $timestamp;
                    }

                    $object->lat = ( (int)$entity->locationE6->latE6 / 1e6 );
                    $object->lng = ( (int)$entity->locationE6->lngE6 / 1e6 );
                    $object->name = $entity->portalV2->descriptiveText->TITLE;

                    switch($entity->controllingTeam->team) {
                        case 'RESISTANCE':
                            $object->faction = Bot::FACTION_HUMAN;
                            break;

                        case 'ALIENS':
                            $object->faction = Bot::FACTION_ALIENS;
                            break;

                        default:
                            $object->faction = Bot::FACTION_ANY;
                    }

                    $level = 0;

                    foreach($entity->resonatorArray->resonators as $resonator) {
                        if ($resonator!== null) {
                            $level+= (int)$resonator->level;
                        }
                    }

                    $object->level = round($level / 8, 2);

                    $stmtInsert->bindValue(1, $object->guid, PDO::PARAM_STR);
                    $stmtInsert->bindValue(2, $object->name, PDO::PARAM_STR);
                    $stmtInsert->bindValue(3, $object->faction, PDO::PARAM_INT);
                    $stmtInsert->bindValue(4, $object->lat, PDO::PARAM_STR);
                    $stmtInsert->bindValue(5, $object->lng, PDO::PARAM_STR);
                    $stmtInsert->bindValue(6, $object->level, PDO::PARAM_INT);
                    $stmtInsert->bindValue(7, $object->timestamp, PDO::PARAM_INT);
                    $stmtInsert->bindValue(8, $object->last_hack_time, PDO::PARAM_INT);
                    $stmtInsert->bindValue(9, $object->burnt_out_time, PDO::PARAM_INT);
                    $stmtInsert->execute();
                }
            }

            $this->pdo->commit();
        } catch(Exception $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Update energy globs
     *
     * @param array $globs Energy globs
     * @param Runner $runner Runner
     * @throws Exception
     */
    public function updateEnergyGlobs(array $globs, Runner $runner)
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT * FROM energy WHERE guid = ? LIMIT 1');
            $stmtInsert = $this->pdo->prepare('INSERT OR REPLACE INTO energy (guid, lat, lng, amount) VALUES (?, ?, ?, ?)');

            for($i = 0, $n = sizeof($globs); $i < $n; $i++) {
                $stmt->bindValue(1, $globs[$i], PDO::PARAM_STR);
                $stmt->execute();

                if (( $object = $stmt->fetch() ) === false) {
                    $object = (object)array(
                        'guid'      => $globs[$i],
                        'lat'       => null,
                        'lng'       => null,
                        'amount'    => null
                    );
                } else {
                    continue;
                }

                /** @var LatLng $location */
                list($location, $amount) = $runner->parseGlob($globs[$i]);

                $object->lat = $location->lat;
                $object->lng = $location->lng;
                $object->amount = (int)$amount;

                $stmtInsert->bindValue(1, $object->guid, PDO::PARAM_STR);
                $stmtInsert->bindValue(2, $object->lat, PDO::PARAM_STR);
                $stmtInsert->bindValue(3, $object->lng, PDO::PARAM_STR);
                $stmtInsert->bindValue(4, $object->amount, PDO::PARAM_INT);
                $stmtInsert->execute();
            }

            $this->pdo->commit();
        } catch(Exception $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Return nearby energy globs
     *
     * @param int $range Range (meters)
     * @return array
     */
    public function findNearbyEnergy($range)
    {
        $stmt = $this->pdo->prepare('SELECT guid, amount, distance(lat, lng) AS distance FROM energy WHERE distance <= ? ORDER BY distance ASC');
        $stmt->bindValue(1, $range, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Return list of nearby portals
     *
     * @param int $range Range (meters)
     * @param int $faction Faction
     * @param int $level Level
     * @return array
     */
    public function findNearbyPortals($range, $faction = Bot::FACTION_ANY, $level = 0)
    {
        $where = array(
            'distance <= ?',
            'level >= ?',
            '(last_hack_time IS NULL OR last_hack_time < ?)',
            '(burnt_out_time IS NULL OR burnt_out_time < ?)'
        );

        $params = array(
            array(1, $range, PDO::PARAM_INT),
            array(2, $level, PDO::PARAM_INT),
            array(3, ( time() - Bot::HACK_INTERVAL ), PDO::PARAM_INT),
            array(4, ( time() - Bot::BURNT_RECOVER_INTERVAL ), PDO::PARAM_INT)
        );

        if ($faction != Bot::FACTION_ANY) {
            $where[] = 'faction = ?';
            $params[] = array(5, $faction, PDO::PARAM_INT);
        }

        $stmt = $this->pdo->prepare('SELECT guid, name, lat, lng, distance(lat, lng) AS distance, level FROM portals WHERE ' . implode(' AND ', $where) . ' ORDER BY distance ASC');

        foreach($params as $param) {
            $stmt->bindParam($param[0], $param[1], $param[2]);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Update portal
     *
     * @param string $guid Portal GUID
     * @param int $lastHackTime Portal hack time
     * @param bool $burntOut "burnt-out" time
     */
    public function updatePortal($guid, $lastHackTime = null, $burntOut = null)
    {
        if ($lastHackTime === null && $burntOut === null) {
            return; // nothing to update
        }

        $stmt = $this->pdo->prepare('UPDATE portals SET last_hack_time = ?, burnt_out_time = ? WHERE guid = ?');
        $stmt->bindValue(1, $lastHackTime, PDO::PARAM_INT);
        $stmt->bindValue(2, $burntOut, PDO::PARAM_INT);
        $stmt->bindValue(3, $guid, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Delete energy globs
     *
     * @param array $globs Energy globs
     * @param int $scannerRange Scanner range
     * @throws Exception
     */
    public function deleteEnergyGlobs(array $globs, $scannerRange = null)
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('DELETE FROM energy WHERE guid = ?');

            for($i = 0, $n = sizeof($globs); $i < $n; $i++) {
                $stmt->bindValue(1, $globs[$i], PDO::PARAM_STR);
                $stmt->execute();
            }

            if ($scannerRange !== null) {
                /**
                 * Delete energy beyond the scanner range
                 */

                $stmt = $this->pdo->prepare('DELETE FROM energy WHERE distance(lat, lng) > ?');
                $stmt->bindValue(1, $scannerRange, PDO::PARAM_INT);
                $stmt->execute();
            }

            $this->pdo->commit();
        } catch(Exception $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Delete entity GUIDs
     *
     * @param array $guids GUIDs
     * @throws Exception
     */
    public function deleteEntityGuids(array $guids)
    {
        $this->pdo->beginTransaction();

        try {
            $stmtDeleteFromInventory = $this->pdo->prepare('DELETE FROM inventory WHERE guid = ?');
            $stmtDeleteFromPortals = $this->pdo->prepare('DELETE FROM portals WHERE guid = ?');

            for($i = 0, $n = sizeof($guids); $i < $n; $i++) {
                $stmtDeleteFromInventory->bindValue(1, $guids[$i], PDO::PARAM_STR);
                $stmtDeleteFromInventory->execute();

                $stmtDeleteFromPortals->bindValue(1, $guids[$i], PDO::PARAM_STR);
                $stmtDeleteFromPortals->execute();
            }

            $this->pdo->commit();
        } catch(Exception $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Return items in the inventory
     *
     * @return array
     */
    public function listInventory()
    {
        $stmt = $this->pdo->prepare('SELECT type, level, rarity, COUNT(type) AS n, SORT_ORDER(type, level, rarity) AS s FROM inventory GROUP BY type, level, rarity, s ORDER BY s ASC');
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count items in the inventory
     *
     * @return int
     */
    public function countInventory()
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inventory');
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * Create database connection
     *
     * @param string $dbfile Database file
     * @param string $schema Schema SQL file
     */
    protected function createConnection($dbfile, $schema)
    {
        $createSchema = !is_file($dbfile);

        $this->pdo = new PDO('sqlite:' . $dbfile);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        /**
         * Database optimization
         */

        $this->pdo->exec('PRAGMA foreign_keys = off');
        $this->pdo->exec('PRAGMA journal_mode = MEMORY');
        $this->pdo->exec('PRAGMA default_cache_size = 10000');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA locking_mode = EXCLUSIVE');

        $this->pdo->sqliteCreateFunction('SORT_ORDER', function($type, $level, $rarity) {
            $raritySort = array(
                'VERY_COMMON' => 1,
                'COMMON'      => 2,
                'LESS_COMMON' => 3,
                'RARE'        => 4,
                'VERY_RARE'   => 5,
                'EXTRA_RARE'  => 6
            );

            $typeSort = array(
                'EMITTER_A'       => 0,
                'EMP_BURSTER'     => 10,
                'ADA'             => 20,
                'JARVIS'          => 25,
                'RES_SHIELD'      => 30,
                'FORCE_AMP'       => 40,
                'TURRET'          => 50,
                'HEATSINK'        => 60,
                'MULTIHACK'       => 70,
                'LINK_AMPLIFIER'  => 80,
                'POWER_CUBE'      => 90,
                'MEDIA'           => 100,
                'PORTAL_LINK_KEY' => 110
            );

            $order = $typeSort[$type];

            switch($type) {
                case 'EMITTER_A':
                case 'EMP_BURSTER':
                case 'POWER_CUBE':
                case 'MEDIA':
                    $order+= (int)$level;
                    break;

                case 'RES_SHIELD':
                case 'FORCE_AMP':
                case 'TURRET':
                case 'HEATSINK':
                case 'MULTIHACK':
                case 'LINK_AMPLIFIER':
                    $order+= $raritySort[$rarity];
                    break;
            }

            return $order;
        }, 3);

        if ($createSchema) {
            $this->createSchema($schema);
        }
    }

    /**
     * Create tables and indexes
     *
     * @param string $schema SQL schema file
     * @throws InvalidArgumentException
     */
    protected function createSchema($schema)
    {
        if (( $schema = realpath($schema) ) === false || !is_file($schema)) {
            throw new InvalidArgumentException('Storage schema SQL file does not exist');
        }

        $schema = file_get_contents($schema);
        $schema = explode(';', $schema);

        foreach($schema as $sql) {
            if ( empty($sql) ) {
                continue;
            }

            $this->pdo->exec($sql);
        }
    }
}