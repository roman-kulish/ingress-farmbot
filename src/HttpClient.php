<?php

/**
 * Class HttpClient
 */
class HttpClient 
{
    /**
     * Handshake URL
     */
    const HANDSHAKE_URL = 'https://m-dot-betaspike.appspot.com/handshake?json=';

    /**
     * RPC URL
     */
    const RPC_URL = 'https://m-dot-betaspike.appspot.com/rpc/';

    /**
     * Username
     *
     * @var string
     */
    protected $username = null;

    /**
     * Password
     *
     * @var string
     */
    protected $password = null;

    /**
     * App info
     *
     * @var stdClass
     */
    protected $appInfo = null;

    /**
     * Device info
     *
     * @var stdClass
     */
    protected $deviceInfo = null;

    /**
     * Cookie file
     *
     * @var string
     */
    protected $cookie = null;

    /**
     * Security token
     *
     * @var string
     */
    protected $xsrfToken = null;

    /**
     * cURL resource
     *
     * @var resource
     */
    protected $curl = null;

    /**
     * Constructor
     *
     * @param stdClass $account Account details
     * @param string $cookie Cookie
     * @throws InvalidArgumentException
     */
    public function __construct(stdClass $account, $cookie)
    {
        if ( !isset($account->user) ) {
            throw new InvalidArgumentException('User account data is not configure on JSON: no "user" property');
        } else {
            $user = (object)$account->user;

            if ( !isset($user->username) ) {
                throw new InvalidArgumentException('User account data is not configure on JSON: no "user->username" property');
            } else if ( !isset($user->password) ) {
                throw new InvalidArgumentException('User account data is not configure on JSON: no "user->password" property');
            }

            $this->username = $user->username;
            $this->password = $user->password;
        }

        if ( !isset($account->appInfo) ) {
            throw new InvalidArgumentException('User account data is not configure on JSON: no "appInfo" property');
        } else {
            $this->appInfo = (object)$account->appInfo;
        }

        if ( !isset($account->deviceInfo) ) {
            throw new InvalidArgumentException('User account data is not configure on JSON: no "deviceInfo" property');
        } else {
            $this->deviceInfo = (object)$account->deviceInfo;
        }

        $this->cookie = $cookie;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->closeCurl();
    }

    /**
     * Set token
     *
     * @param string $xsrfToken Security token
     */
    public function setXSRFToken($xsrfToken)
    {
        $this->xsrfToken = $xsrfToken;
    }

    /**
     * Perform handshake
     *
     * @return stdClass
     * @throws RuntimeException
     */
    public function handshake()
    {
        $urlParams = clone $this->appInfo;
		
		/**
		 * @todo encrypt & include device info
		 */
//        $urlParams->a = clone $this->deviceInfo;

        $options = array(
            CURLOPT_VERBOSE        => 0,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT      => 'Nemesis (gzip)',
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_HTTPGET        => true,
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $this->cookie,
            CURLOPT_COOKIEFILE     => $this->cookie,
            CURLOPT_HTTPGET        => true,
            CURLOPT_URL            => self::HANDSHAKE_URL . urlencode( json_encode($urlParams) )
        );

        if (( $curl = curl_init() ) === false) {
            throw new RuntimeException('Cannot initialize cURL');
        } else if ( curl_setopt_array($curl, $options) === false ) {
            throw new RuntimeException( sprintf('Cannot set cURL options [%d, %s]', curl_errno($curl), curl_error($curl)) );
        }

        $response = curl_exec($curl);

        if ( (bool)preg_match('/\<title\>Google Accounts\<\/title\>/', $response) ) {

            /**
             * HTTPS authentication requested
             */

            $document = new DOMDocument();

            if (( @$document->loadHtml($response) ) === false) {
                throw new RuntimeException('Cannot parse HTML with DOMDocument');
            }

            $xpath = new DOMXpath($document);

            $action = ( $xpath->query('//form[@id="gaia_loginform"]')->item(0)->getAttribute('action') );
            $fields = array();

            foreach($xpath->query('//form[@id="gaia_loginform"]//input') as $input) {
                $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            }

            $options = array(
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => array_merge($fields, array('Email' => $this->username, 'Passwd' => $this->password)),
                CURLOPT_URL        => $action
            );

            if ( curl_setopt_array($curl, $options) === false ) {
                throw new RuntimeException( sprintf('Cannot set cURL options [%d, %s]', curl_errno($curl), curl_error($curl)) );
            }

            $response = curl_exec($curl);
        }

        if (( $n = strpos($response, '{') ) != false) {
            $response = substr($response, $n);
        }

        if (( $response = @json_decode($response) ) === null) {
            throw new RuntimeException('cannot decode JSON, invalid response');
        }

        return $response;
    }

    /**
     * Send request
     *
     * @param string $action Action to trigger
     * @param stdClass $data Data to send
     * @return stdClass
     * @throws RuntimeException
     */
    public function sendRequest($action, stdClass $data)
    {
        if ( !is_resource($this->curl) ) {
            $this->initCurl();
        } else if ( empty($this->xsrfToken) ) {
            throw new RuntimeException('Security token is not set');
        }

        $data = json_encode($data);

        $headers = array(
            'Content-Type: application/json;charset=UTF-8',
            'Connection: Keep-Alive',
            'X-XsrfToken: ' . $this->xsrfToken
        );

        $options = array(
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_URL        => self::RPC_URL . ltrim($action, '/')
        );

        if ( curl_setopt_array($this->curl, $options) === false ) {
            throw new RuntimeException( sprintf('Cannot set cURL options [%d, %s]', curl_errno($this->curl), curl_error($this->curl)) );
        } else if (( $response = curl_exec($this->curl) ) === false) {
            throw new RuntimeException( sprintf('Cannot fetch [%d, %s]', curl_errno($this->curl), curl_error($this->curl)) );
        }

        if (( $response = @json_decode($response) ) === null) {
            throw new RuntimeException('cannot decode JSON, invalid response');
        }

        return $response;
    }

    /**
     * Initialize cURL
     *
     * @throws RuntimeException
     */
    protected function initCurl()
    {
        $options = array(
            CURLOPT_VERBOSE        => 0,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT      => 'Nemesis (gzip)',
			CURLOPT_ENCODING       => 'gzip',
            CURLOPT_POST           => true,
            CURLOPT_COOKIEJAR      => $this->cookie,
            CURLOPT_COOKIEFILE     => $this->cookie
        );

        if (( $curl = curl_init() ) === false) {
            throw new RuntimeException('Cannot initialize cURL');
        } else if ( curl_setopt_array($curl, $options) === false ) {
            throw new RuntimeException( sprintf('Cannot set cURL options [%d, %s]', curl_errno($curl), curl_error($curl)) );
        }

        $this->curl = $curl;
    }

    /**
     * Close cURL resource
     */
    protected function closeCurl()
    {
        if ( is_resource($this->curl) ) {
            curl_close($this->curl);
        }
    }
}