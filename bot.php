<?php

require_once 'src/LatLng.php';
require_once 'src/Geo.php';
require_once 'src/Runner.php';
require_once 'src/Storage.php';
require_once 'src/HttpClient.php';
require_once 'src/Util.php';
require_once 'src/Bot.php';

/**
 * Terminate execution
 *
 * @param string|null $message Error
 * @param bool $showHelp Show help
 */
function terminate($message = null, $showHelp = false)
{
    printf("\n");

    if ( !empty($message) ) {
        printf("%s\n\n", $message);
    }

    if ( (boolean)$showHelp ) {
        printf("php %s -u NICKNAME [-l LEVEL] [-f FACTION] [-h]\n", basename(__FILE__));
        printf("   -u   - Nickname\n");
        printf("   -l   - Minimum portal level to hack\n");
        printf("   -f   - Faction, [green|alien] or [human|blue]\n");
        printf("   -h   - Display this help\n\n");
    }

    exit();
};

/**
 * =======================================================================
 *     Global settings
 * =======================================================================
 */

$settings = array(
    'level'      => 0,
    'faction'    => Bot::FACTION_ANY,
    'location'   => null,
    'account'    => __DIR__ . '/account',
    'cookie'     => null,
    'storage'    => __DIR__ . '/db/',
    'schema'     => __DIR__ . '/db/schema.sql',
    'runner_dir' => __DIR__
);

/**
 * =======================================================================
 *     Parse options
 * =======================================================================
 */

list($settings['account'], $settings['cookie'], $settings['storage'], $settings['level'], $settings['faction']) = call_user_func(function($options) use($settings) {
    isset($options['h']) && terminate(null, true); // show help
    isset($options['u']) || terminate('Error: user name must be specified', true); // check account

    if (( $accountDir = realpath($settings['account']) ) === false || !is_dir($accountDir)) {
        terminate('Error: account directory does not exist or path is invalid');
    } else {
        $accountDir = rtrim($accountDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    if (( $storageDir = realpath($settings['storage']) ) === false || !is_dir($storageDir)) {
        terminate('Error: storage directory does not exist or path is invalid');
    } else {
        $storageDir = rtrim($storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    $username = strtolower($options['u']);
    $account = $accountDir . $username . '.json';

    if ( !is_file($account) ) {
        terminate('Error: no such account "' . $options['u'] . '"');
    } else {
        $account = file_get_contents($account);
    }

    if (( $account = @json_decode($account) ) === false) {
        terminate('Error: invalid accounts file, cannot parse JSON');
    }

    $cookie_jar = $accountDir . $username . '.cookie.txt';

    if ( !is_file($cookie_jar) ) {
        touch($cookie_jar);
    }

    $level = ( isset($options['l']) ? (int)$options['l'] : 0 );
    $level = ( $level < 0 ? 0 : $level );

    $faction_mapping = array(
        'green' => Bot::FACTION_ALIENS,
        'alien' => Bot::FACTION_ALIENS,
        'human' => Bot::FACTION_HUMAN,
        'blue'  => Bot::FACTION_HUMAN
    );

    if (( $faction = ( isset($options['f']) ? strtolower( trim($options['f']) ) : false) ) !== false) {
        if ( !isset($faction_mapping[$faction]) ) {
            terminate( sprintf('Error: invalid faction name "%s"', $faction), true );
        }

        $faction = $faction_mapping[$faction];
    } else {
        $faction = Bot::FACTION_ANY;
    }

    return array($account, $cookie_jar, ($storageDir . $username . '.db'), $level, $faction);
}, getopt('hl:f:u:'));

/**
 * =======================================================================
 *     Request location
 * =======================================================================
 */

$settings['location'] = call_user_func(function() {
    $url = 'http://maps.googleapis.com/maps/api/geocode/json?address={QUERY}&sensor=true&language=en';

    $options = array(
        CURLOPT_VERBOSE        => 0,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HEADER         => 0,
        CURLOPT_RETURNTRANSFER => 1
    );

    if (( $curl = curl_init() ) === false) {
        terminate('Error: cannot initialize cURL');
    } else if ( curl_setopt_array($curl, $options) === false ) {
        terminate( sprintf('Error: cannot set cURL options [%d, %s]', curl_errno($curl), curl_error($curl)) );
    }

    $location = false;

    $inLocation = 'Search location (\'exit\' to terminate): /> ';
    $inSelectLocation = 'Select location (\'exit\' to terminate, 0 to repeat search): /> ';

    while(true) {

        /**
         * Request Search location
         */

        printf($inLocation);

        while(( $line = fgets(STDIN) ) !== false) {
            $line = trim($line);

            if ($line != '') {
                if ($line == 'exit') {
                    exit; // exit script
                }

                if ( curl_setopt($curl, CURLOPT_URL, str_replace('{QUERY}', urlencode($line), $url)) === false) {
                    terminate( sprintf('Error: cannot set CURLOPT_URL [%d, %s]', curl_errno($curl), curl_error($curl)) );
                } else if (( $search = curl_exec($curl) ) === false) {
                    terminate( sprintf('Error: cannot fetch [%d, %s]', curl_errno($curl), curl_error($curl)) );
                }

                if (( $search = @json_decode($search) ) === null) {
                    terminate('Error: cannot decode JSON, invalid response');
                }

                printf("\n");

                if ($search->status == 'ZERO_RESULTS') {
                    printf("No locations found\n\n%s", $inLocation);
                    continue;
                } else if ($search->status != 'OK') {
                    terminate( sprintf('Error: search error "%s"', $search->status) );
                }

                break;
            }

            printf($inLocation);
        }

        printf("Found locations:\n\n");

        $options = array();
        $i = 1;

        foreach($search->results as $result) {
            $options[] = array(
                $result->geometry->location->lat,
                $result->geometry->location->lng
            );

            printf("    % 2d. %s\n", $i++, $result->formatted_address);
        }

        printf("\n%s", $inSelectLocation);

        while(( $line = fgets(STDIN) ) !== false) {
            $line = trim($line);

            if ($line != '') {
                if ($line == 'exit') {
                    exit; // exit script
                } else if ($line == '0') {
                    break; // back to search
                }

                $line = ( (int)$line - 1 );

                printf("\n");

                if ($line < 0 || $line >= sizeof($options)) {
                    printf("Not an option\n\n%s", $inSelectLocation);
                    continue;
                }

                $location = $options[$line];
                break 2;
            }

            printf($inSelectLocation);
        }
    }

    curl_close($curl);
    return new LatLng($location[0], $location[1]);
});

/**
 * =======================================================================
 *     Initialize and run the bot
 * =======================================================================
 */

try {
    $bot = new Bot($settings);
    $bot->farm();
} catch (Exception $exception) {
    terminate($exception->getMessage());
}