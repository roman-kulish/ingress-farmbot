<?php

/**
 * Class Bot
 */
final class Bot
{
    /**
     * Filter: all factions
     */
    const FACTION_ANY   = 0;

    /**
     * Filter: alien faction
     */
    const FACTION_ALIENS = 1;

    /**
     * Filter human faction
     */
    const FACTION_HUMAN = 2;

    /**
     * Minimum energy threshold
     */
    const MIN_ENERGY_THRESHOLD = 500;

    /**
     * Minimum travel speed
     */
    const SPEED_MIN = 1;

    /**
     * Maximum travel speed
     */
    const SPEED_MAX = 4;

    /**
     * Scanner access area
     */
    const SCANNER_AREA = 40;

    /**
     * Range to look for new portals to move to
     */
    const PORTALS_RANGE = 500;

    /**
     * Waiting time between hacks (seconds)
     */
    const HACK_INTERVAL = 300;

    /**
     * Waiting time between hacking portals that are burnt out (seconds)
     */
    const BURNT_RECOVER_INTERVAL = 28800;

    /**
     * Bot location
     *
     * @var LatLng
     */
    protected $location = null;

    /**
     * Bot XM level
     *
     * @var int
     */
    protected $xmLevel = 0;

    /**
     * Bot inventory size
     *
     * @var int
     */
    protected $inventorySize = 0;

    /**
     * Update interval (seconds)
     *
     * @var int
     */
    protected $updateInterval = 0;

    /**
     * Update interval (meters)
     *
     * @var int
     */
    protected $updateDistance = 0;

    /**
     * Scanner range (meters)
     *
     * @var int
     */
    protected $scannerRange = 0;

    /**
     * Maximum energy level
     *
     * @var int
     */
    protected $maxEnergyThreshold = null;

    /**
     * Maximum inventory size
     *
     * @var int
     */
    protected $maxInventorySize = null;

    /**
     * Distance bot has travelled
     *
     * @var int
     */
    protected $distanceTravelled = 0;

    /**
     * Last update timestamp
     *
     * @var int
     */
    protected $lastUpdated = 0;

    /**
     * Last inventory update timestamp
     *
     * @var int
     */
    protected $lastInventoryUpdated = 0;

    /**
     * Consumed energy globs
     *
     * @var array
     */
    protected $consumedEnergyGlobs = array();

    /**
     * Portal faction filter
     *
     * @var int
     */
    protected $filterFaction = self::FACTION_ANY;

    /**
     * Portal level filter
     *
     * @var int
     */
    protected $filterLevel = 0;

    /**
     * Stop flag
     *
     * @var bool
     */
    protected $stop = false;

    /**
     * Runner wrapper
     *
     * @var Runner
     */
    protected $runner = null;

    /**
     * HTTP Client
     *
     * @var HttpClient
     */
    protected $httpClient = null;

    /**
     * Sqlite storage
     *
     * @var Storage
     */
    protected $storage = null;

    /**
     * Constructor
     *
     * @param array $settings Bot settings
     */
    public function __construct(array $settings)
    {
        $this->location = $settings['location'];
        $this->filterFaction = $settings['faction'];
        $this->filterLevel = $settings['level'];
        $this->runner = new Runner($settings['runner_dir']);
        $this->httpClient = new HttpClient($settings['account'], $settings['cookie']);

        $this->storage = new Storage($settings['storage'], $settings['schema']);
        $this->storage->useLocation($this->location);

        $this->performHandshake();
        $this->updateInventory();
    }

    /**
     * Farm area
     */
    public function farm()
    {
        while(!$this->stop) {
            if ($this->distanceTravelled > $this->updateDistance || ( time() - $this->lastUpdated ) > $this->updateInterval) {
                $this->updateWorld();
            }

            if (( time() - $this->lastInventoryUpdated ) > $this->updateInterval) {
                $this->updateInventory();
            }

            if ($this->xmLevel < $this->maxEnergyThreshold) {
                $this->collectEnergy();
            }

            $this->hackNearbyPortals();
            $this->moveToNextPortal();
        }
    }

    /**
     * Perform handshake with the server
     */
    protected function performHandshake()
    {
        printf("Performing handshake ... ");

        $data = $this->httpClient->handshake()->result;

        if ($data->pregameStatus->action != 'NO_ACTIONS_REQUIRED') {
            throw new Exception('Game requires actions this bot cannot handle: ' . $data->pregameStatus->action);
        }

        printf("[  OK  ]\n");

        $playerData = $data->playerEntity[2];
        $scannerKnowbs = $data->initialKnobs->bundleMap->ScannerKnobs;
        $InventoryKnobs = $data->initialKnobs->bundleMap->InventoryKnobs;

        $level = Util::levelForAp($playerData->playerPersonal->ap);

        $this->xmLevel = (int)$playerData->playerPersonal->energy;
        $this->updateInterval = ( (int)$scannerKnowbs->updateIntervalMs / 1000 );
        $this->updateDistance = (int)$scannerKnowbs->updateDistanceM;
        $this->scannerRange = (int)$scannerKnowbs->rangeM;
        $this->httpClient->setXSRFToken($data->xsrfToken);
        $this->maxEnergyThreshold = Util::maxXMForLevel($level);
        $this->maxInventorySize = (int)$InventoryKnobs->maxInventoryItems;

        printf("  > Nickname     %s\n", $data->nickname);
        printf("  > Team         %s\n", Util::faction($playerData->controllingTeam->team));
        printf("  > Level        %d (AP %d)\n", $level, $playerData->playerPersonal->ap);
        printf("  > Energy       %d from %d\n", $this->xmLevel, $this->maxEnergyThreshold);
        printf("  > Location     %s\n", $this->location->toString());
        printf("\n");
    }

    /**
     * Load inventory
     */
    protected function updateInventory()
    {
        $formatLine = function($type, $level, $rarity, $n)
        {
            $item = Util::itemName($type, $level, $rarity);
            $item = str_pad($item, 25, ' ', STR_PAD_RIGHT);
            $item = sprintf("  > %s %- 4d    ", $item, $n);

            return $item;
        };

        printf("Refreshing inventory ... ");

        $data = $this->httpClient->sendRequest('playerUndecorated/getInventory', $this->buildRequestData(array(
            'lastQueryTimestamp' => ($this->lastUpdated == 0 ? time() : $this->lastUpdated)
        )));

        printf("[  OK  ]\n");

        $this->storage->cleanInventory(); // remove items from inventory prior sync

        $this->processGameBasket($data);
        $this->lastInventoryUpdated = time();

        $inventory = $this->storage->listInventory();
        $buffer = '';

        for($i = 0, $n = sizeof($inventory), $m = (int)round($n / 2); $i < $m; $i++) {
            $resource = $inventory[$i];

            $buffer.= $formatLine($resource['type'], $resource['level'], $resource['rarity'], $resource['n']);

            if (($i + 1) == $m && ($n % 2) > 0) {
                $buffer.= "\n";
            } else {
                $resource = $inventory[$i + $m];
                $buffer.= $formatLine($resource['type'], $resource['level'], $resource['rarity'], $resource['n']) . "\n";
            }
        }

        printf("%s\nTOTAL: %d\n\n", $buffer, $this->inventorySize);
    }

    /**
     * Update world
     */
    protected function updateWorld()
    {
        printf("Refreshing location data ... ");

        list($sw, $ne) = Geo::getBounds($this->location, $this->scannerRange);
        $cellsAsHex = $this->runner->getCells($sw, $ne);

        $data = $this->httpClient->sendRequest('gameplay/getObjectsInCells', $this->buildRequestData(array(
            'cellsAsHex' => $cellsAsHex,
		    'dates'      => array_fill(0, sizeof($cellsAsHex), 0)
        )));

        printf("[  OK  ]\n");

        $this->processGameBasket($data);

        $this->lastUpdated = time();
        $this->distanceTravelled = 0;
    }

    /**
     * Consumes energy globs
     */
    protected function collectEnergy()
    {
        $globs = $this->storage->findNearbyEnergy(self::SCANNER_AREA);

        if (( $n = sizeof($globs) ) > 0) {

            /**
             * There are energy globs around the bot can consume
             */

            printf("Collecting energy ... %d -> ", $this->xmLevel);

            for ($i = 0; $i < $n && $this->xmLevel < $this->maxEnergyThreshold; $i++) {
                $this->xmLevel+= (int)$globs[$i]->amount;
                $this->consumedEnergyGlobs[] = $globs[$i]->guid;
            }

            $this->storage->deleteEnergyGlobs($this->consumedEnergyGlobs);
            printf("%d [  OK  ]\n", $this->xmLevel);
        }
    }

    /**
     * Hack portals in the reach of the scanner
     */
    protected function hackNearbyPortals()
    {
        /**
         * Portals in the range of (self::SCANNER_AREA - 5) are considered hackable.
         * Range is smaller to stay closer to the portal.
         */

        $portals = $this->storage->findNearbyPortals(self::SCANNER_AREA - 5, $this->filterFaction, $this->filterLevel);

        for ($i = 0, $n = sizeof($portals); $i < $n; $i++) {
            if ($this->xmLevel < $this->maxEnergyThreshold) {
                $this->collectEnergy(); // collect more energy before hacking
            }

            if ($this->xmLevel < self::MIN_ENERGY_THRESHOLD) {

                /**
                 * There is no energy near the current portal, so bot cannot hack it and
                 * time out is set to force the bot to try the luck with another portal.
                 */

                $this->storage->updatePortal($portals[$i]->guid, time());
                break; // save energy
            }

            printf("Hacking portal \"%s\" ... ", Util::portalName($portals[$i]->name, $portals[$i]->level));

            $data = $this->httpClient->sendRequest('gameplay/collectItemsFromPortal', $this->buildRequestData(array(
                'itemGuid' => $portals[$i]->guid
            )));

            $burntOut = null;
            $lastHackTime = null;
            $break = false;
            $result = '  OK  ';

            if ( isset($data->error) ) {
                if ($data->error == 'TOO_OFTEN' || $data->error == 'SERVER_ERROR') {
                    $burntOut = time();
                } else if ($data->error == 'OUT_OF_RANGE' || $data->error == 'NEED_MORE_ENERGY') {
                    $break = true;
                }

                $result = $data->error;
            } else {
                $lastHackTime = time();
            }

            printf("[%s]\n\n", $result);

            $this->storage->updatePortal($portals[$i]->guid, $lastHackTime, $burntOut);
            $this->processGameBasket($data, true);

            if ($this->inventorySize >= $this->maxInventorySize) {
                return $this->terminate('Inventory is full');
            } else if ($break) {
                break;
            }
        }

        if ($this->xmLevel < $this->maxEnergyThreshold) {
            $this->collectEnergy(); // collect more energy after hack
        }
    }

    /**
     * Move between portals
     */
    protected function moveToNextPortal()
    {
        $portals = $this->storage->findNearbyPortals(self::PORTALS_RANGE, $this->filterFaction, $this->filterLevel);

        if ( sizeof($portals) == 0 ) {
            return $this->terminate('No portals are in range to hack');
        }

        $distance = mt_rand(self::SPEED_MIN, self::SPEED_MAX);
        $this->location = Geo::offsetDistance($this->location, new LatLng($portals[0]->lat, $portals[0]->lng), $distance);
        $this->distanceTravelled+= $distance;
        $this->storage->useLocation($this->location);

        printf("Moving to \"%s\" ... %d meters\n", Util::portalName($portals[0]->name, $portals[0]->level), ($portals[0]->distance - $distance));
        sleep(1);
    }

    /**
     * Processes data returned with request
     *
     * @param stdClass $data Game basket data
     * @param bool $listItems whether to print items information or not
     */
    protected function processGameBasket(stdClass $data, $listItems = false)
    {
        $gameBasket = $data->gameBasket;

        if ( isset($gameBasket->inventory) && sizeof($gameBasket->inventory) > 0 ) {
            $this->storage->updateInventory($gameBasket->inventory, $listItems);
            $this->inventorySize = $this->storage->countInventory();

            printf("* Inventory updated ... %d items\n", $this->storage->countInventory());
        }

        if ( isset($gameBasket->gameEntities) && sizeof($gameBasket->gameEntities) > 0 ) {
            $this->storage->updatePortals($gameBasket->gameEntities);
        }

        if ( isset($gameBasket->playerEntity) && sizeof($gameBasket->playerEntity) > 0 ) {
            $this->xmLevel = (int)$gameBasket->playerEntity[2]->playerPersonal->energy;

            printf(
                "* Energy level updated ... %d from %d ... L%d (AP %d)\n",
                $this->xmLevel,
                $this->maxEnergyThreshold,
                Util::levelForAp($gameBasket->playerEntity[2]->playerPersonal->ap),
                $gameBasket->playerEntity[2]->playerPersonal->ap
            );
        }

        if ( isset($gameBasket->energyGlobGuids) && sizeof($gameBasket->energyGlobGuids) > 0 ) {
            $this->storage->updateEnergyGlobs($gameBasket->energyGlobGuids, $this->runner);
        }

        if ( isset($gameBasket->deletedEntityGuids) && sizeof($gameBasket->deletedEntityGuids) > 0 ) {
            $this->storage->deleteEnergyGlobs($gameBasket->deletedEntityGuids, $this->scannerRange);
            $this->storage->deleteEntityGuids($gameBasket->deletedEntityGuids);
        }

        printf("\n");
    }

    /**
     * Build request data
     *
     * @param array $params Action parameters
     * @return stdClass
     */
    protected function buildRequestData(array $params)
    {
        $params = array_merge($params, array(
            'knobSyncTimestamp' => time(),
            'playerLocation'    => $this->location->toE6String(),
            'location'          => $this->location->toE6String(),
            'energyGlobGuids'   => $this->consumedEnergyGlobs
        ));

        $this->consumedEnergyGlobs = array();
        return (object)array('params' => (object)$params);
    }

    /**
     * Gracefully terminate execution
     *
     * @param string $message Exit message
     */
    protected function terminate($message)
    {
        if ( !empty($message) ) {
            printf("\n%s\n\n", $message);
        }

        $this->stop = true;
    }
}