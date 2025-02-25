<?php

namespace IDCT\Networking\Soap;

use Exception;
use SoapClient;

class Client extends SoapClient
{
    /**
     * Defines if request will be sent with a basic http auth
     *
     * @var boolean
     */
    protected bool $auth = false;

    /**
     * If auth set to true this will be sent as login for the basic http auth
     *
     * @var string|null
     */
    protected ?string $authLogin = null;

    /**
     * If auth set to true this will be sent as password for the basic http auth
     *
     * @var string|null
     */
    protected ?string $authPassword = null;

    /**
     * Value of the content-type header sent with every message.
     * Defaults to text/xml, yet as some services expect type/application-xml
     * or application/soap+xml allows override the default type.
     *
     * @var string|null
     */
    protected ?string $contentType = null;

    /**
     * Associative array of custom headers to sent together with the request
     *
     * @var array
     */
    protected array $customHeaders;

    /**
     * If set to false then request will not fail in case of invalid SSL cert
     *
     * @var boolean
     */
    protected bool $ignoreCertVerify;

    /**
     * Connection negotiation timeout in seconds
     *
     * @var int
     */
    protected int $negotiationTimeout;

    /**
     * Number of retries until exception is thrown
     *
     * @var int
     */
    protected int $persistanceFactor;

    /**
     * Read timeout (after a successful connection) in seconds)
     *
     * @var int
     */
    protected int $persistanceTimeout;

    /**
     * Last connection's error number. Returned from curl_errno. 0 means no error.
     * `null` means that no query was executed yet.
     *
     * @var int|null
     */
    protected ?int $lastConnErrNo;

    /**
     * Constructor of the new object. Creates an instance of the new SoapClient.
     * Sets default values of the timeouts and number of retries.
     *
     * @param string   $wsdl               Url of the WebService's wsdl
     * @param array    $options            PHP SoapClient's array of options
     * @param int      $negotiationTimeout Connection timeout in seconds. 0 to disable.
     * @param int      $persistanceFactor  Number of retries.
     * @param int|null $persistanceTimeout Read timeout in seconds. 0 to disable. null to use ini default_socket_timeout
     *
     * @throws \SoapFault
     * @throws \Exception
     */
    public function __construct( $wsdl, array $options = [], int $negotiationTimeout = 0, int $persistanceFactor = 1, int $persistanceTimeout = null)
    {
        if ($persistanceTimeout === null) {
            //let us try default to default_socket_timeout
            $iniDefaultSocketTimeout = ini_get('default_socket_timeout');
            $persistanceTimeout = $iniDefaultSocketTimeout ? $iniDefaultSocketTimeout : 0; //if setting missing default to disabled value (0)
        }

        $this->setNegotiationTimeout($negotiationTimeout)
             ->setPersistanceFactor($persistanceFactor)
             ->setPersistanceTimeout($persistanceTimeout)
             ->setIgnoreCertVerify(false)
             ;

        if (array_key_exists("login", $options)) {
            $this->auth = true;
            $this->authLogin = $options['login'];
            if (array_key_exists("password", $options)) {
                $this->authPassword = $options['password'];
            } else {
                $this->authPassword = null;
            }
        }

        $this->customHeaders = [];
        parent::__construct($wsdl, $options);
    }

    /**
     * Performs the request using cUrl, should not be called directly, but through
     * normal usage of PHP SoapClient (using particular methods of the WebService).
     * Throws an exception if connection or data read fails more than the number of retries (persistanceFactor).
     * Returns data response / content.
     *
     * @param string    $request  Request (XML/Data) to be sent to the WebService parsed by SoapClient.
     * @param string    $location WebService URL.
     * @param string    $action   Currently not used. In the signature for compatibility with SoapClient. TODO: to be used with particular soap versions.
     * @param int       $version  Currently not used. In the signature for compatibility with SoapClient. TODO: add Soap Version selection.
     * @param bool|null $oneWay   Currently not used. In the signature for compatibility with SoapClient.
     *
     * @return string|null
     * @throws \Exception
     */
    public function __doRequest( string $request, string $location, string $action, int $version, bool $oneWay = null) : ?string
    {
        $response = "";
        for ($attempt = 0; $attempt < $this->persistanceFactor; $attempt++) {
            $ch = curl_init($location);
            curl_setopt($ch, CURLOPT_HEADER, false);
            if ($oneWay !== true) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->negotiationTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->persistanceTimeout);
            $headersFormatted = $this->buildHeaders($version, $action);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headersFormatted);
            if ($this->getIgnoreCertVerify() === true) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            } else {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            }

            if ($this->auth === true) {
                $credentials = $this->authLogin;
                $credentials .= ($this->authPassword !== null) ? ":" . $this->authPassword : "";
                curl_setopt($ch, CURLOPT_USERPWD, $credentials);
            }

            $response = curl_exec($ch);

            if ( str_contains( $response, 'Content-Type: application/xop+xml' ) )
            {
                $response = \stristr((string) \stristr($response, '<s:'), '</s:Envelope>', true).'</s:Envelope>';
            }

            $this->lastConnErrNo = curl_errno($ch);

            curl_close($ch);
            if (($this->lastConnErrNo === 0) && ($response !== false)) {
                break;
            }
            if ($attempt >= $this->persistanceFactor - 1) {
                throw new Exception('Request failed for the maximum number of attempts.');
            }
        }

        return $response;
    }

    /**
     * Returns last connection's error number. Returned by curl_errno. 0 means
     * that no error occured. `null` means that no query was executed yet.
     *
     * @return int|null
     */
    public function getLastConnErrNo(): ?int {
        return $this->lastConnErrNo;
    }

    /**
     * Gets textual representation of the last error returned by `getLastConnErrNo`.
     *
     * Returns empty string on success.
     *
     * @return string
     */
    public function getLastConnErrText(): string {
        return curl_strerror((int) $this->getLastConnErrNo());
    }

    /**
     * Sets the value of the contentType variable used in the content-type
     * request header. Suggested values: text/xml; application/soap+xml;
     * type/application-xml
     *
     * @param string|null $contentType
     *
     * @return $this
     * @throws \Exception
     */
    public function setContentType( string $contentType = null): static {
        if ($contentType !== null && !is_string($contentType)) {
            throw new Exception('Content-type value must be a valid string or null to use Soap verion defaults.');
        }

        $this->contentType = $contentType;

        return $this;
    }

    /**
     * Returns the value of the contentType variable
     *
     * @return string|null
     */
    public function getContentType(): ?string {
        return $this->contentType;
    }

    /**
     * Sets the negotiation (connection) timeout in seconds.
     * Throws an exception in case a negative value.
     * Set 0 to disable the timeout.
     *
     * @param int $timeoutInSeconds
     *
     * @return $this
     * @throws \Exception
     */
    public function setNegotiationTimeout( int $timeoutInSeconds): static {
        if ($timeoutInSeconds < 0) {
            throw new Exception('Negotiation timeout must be a positive integer or 0 to disable.');
        } else {
            $this->negotiationTimeout = $timeoutInSeconds;
        }

        return $this;
    }

    /**
     * Gets the negotiation (connection) timeout in seconds
     *
     * @return int
     */
    public function getNegotiationTimeout(): int {
        return $this->negotiationTimeout;
    }

    /**
     * Sets the maximum number of full data read (connection+read) retries.
     * Value must be at least equal to one.
     *
     * @param int $attempts
     *
     * @return $this
     * @throws \Exception
     */
    public function setPersistanceFactor( int $attempts): static {
        if ($attempts < 1) {
            throw new Exception('Number of attempts must be at least equal to 1.');
        } else {
            $this->persistanceFactor = $attempts;
        }

        return $this;
    }

    /**
     * Gets the maximum number of full data read (connection+read) retries.
     *
     * @return int
     */
    public function getPersistanceFactor(): int {
        return $this->persistanceFactor;
    }

    /**
     * Sets the data read (after a successful negotiation) timeout in seconds.
     * Throws an exception when value is negative.
     * Set 0 to disable timeout. null to use ini default_socket_timeout
     *
     * @param int|null $timeoutInSeconds
     *
     * @return $this
     * @throws \Exception
     */
    public function setPersistanceTimeout( int $timeoutInSeconds = null): static {
        if ($timeoutInSeconds === null) {
            //let us try default to default_socket_timeout
            $iniDefaultSocketTimeout = ini_get('default_socket_timeout');
            $this->persistanceTimeout = $iniDefaultSocketTimeout ? $iniDefaultSocketTimeout : 0; //if setting missing default to disabled value (0)
        } else {
            if ($timeoutInSeconds < 0) {
                throw new Exception('Persistance timeout must be a positive integer, 0 to disable or null to use ini default_socket_timeout value.');
            } else {
                $this->persistanceTimeout = $timeoutInSeconds;
            }
        }

        return $this;
    }

    /**
     * Gets the data read (after negotiation) timeout in seconds.
     * @return int
     */
    public function getPersistanceTimeout(): int {
        return $this->persistanceTimeout;
    }

    /**
     * Sets an array of custom http headers to be sent together with the request.
     * Throws an exception if not an array.
     *
     * @param array $headers
     *
     * @return $this
     * @throws \Exception
     */
    public function setHeaders( mixed $headers): static {
        if (is_array($headers)) {
            $this->customHeaders = $headers;
        } else {
            throw new Exception('Not an array.');
        }

        return $this;
    }

    /**
     * Gets the array of custom headers to be sent together with the request.
     *
     * @return array
     */
    public function getHeaders(): array {
        return $this->customHeaders;
    }

    /**
     * Sets a custom header to be sent together with the request.
     * Throws an exception if header's name is not at least 1 char long.
     *
     * @param string $header
     * @param string $value
     *
     * @return $this
     * @throws \Exception
     */
    public function setHeader( string $header, string $value): static {
        if (strlen($header) < 1) {
            throw new Exception('Header must be a string.');
        }
        $this->customHeaders[$header] = $value;

        return $this;
    }

    /**
     * Gets a custom header from the array of headers to be sent with the request or null.
     *
     * @param string $header
     *
     * @return string
     */
    public function getHeader( string $header): string {
        return $this->customHeaders[$header];
    }

    /**
     * Sets a boolean value of the flag which indicates if request should not worry about invalid SSL certificate.
     *
     * @param boolean $value
     *
     * @return $this
     */
    public function setIgnoreCertVerify( bool $value): static {
        $this->ignoreCertVerify = $value;

        return $this;
    }

    /**
     * Gets the value of the flag which indicates if request should not worry about invalid SSL certificate.
     * @return boolean
     */
    public function getIgnoreCertVerify(): bool {
        return $this->ignoreCertVerify;
    }

    /**
     * Builds and returns an array of headers, based on custom ones and required
     * by soap call protocol's version (like SOAPAction or specific content type).
     *
     * @param int $version SOAP protocol version (SOAP_1_1, SOAP_1_2)
     *
     * @return string[]
     */
    protected function buildHeaders( int $version, $action): array {
        $headers = $this->customHeaders;

        //add version specific headers
        switch ($version) {
            case SOAP_1_1:
                $headers['Content-Type'] = is_null($this->contentType) ? 'text/xml' : $this->contentType;
                if (!empty($action)) {
                    $headers['SOAPAction'] = '"'. str_replace('"', '\"', $action) . '"';
                }

            break;
            case SOAP_1_2:
                if ($this->contentType === null) {
                    $headers['Content-Type'] = 'application/soap+xml; charset=utf-8';
                    if (!empty($action)) {
                        $headers['Content-Type'] .= '; action="'.str_replace('"', '\"', $action).'"';
                    }
                } else {
                    if (empty($action)) {
                        $headers['Content-Type'] = $this->contentType;
                    } else {
                        //allows usage of SOAPACTION replacement token
                        $headers['Content-Type'] = str_replace("{SOAPACTION}", str_replace('"', '\"', $action), $this->contentType);
                    }
                }
            break;
            default:
                $headers['Content-Type'] = 'application/soap+xml';
        }

        $headersFormatted = [];
        foreach ($headers as $header => $value) {
            $headersFormatted[] = $header . ": " . $value;
        }

        return $headersFormatted;
    }
}
