<?php

namespace App\Services;

/**
 * This class writes text to the VESTA application based on a certain VESTA UID
 * Kudos to stackoverflow so that ancient protocols can still be used: http://stackoverflow.com/questions/14770898/soapenvelope-soap-envenvelope-php
 */
class VestaService
{
    /**
     * SOAP client instance.
     *
     * @var SoapClient
     */
    protected $client;

    /**
     * Vesta user domain.
     */
    protected $domain;

    /**
     * Vesta username.
     */
    protected $username;

    /**
     * Vesta user password.
     */
    protected $password;

    /**
     * Singleton class instance.
     *
     * @var VestaService
     */
    private static $instance;

    /**
     * Private contructor for Singleton pattern
     */
    private function __construct() {}

    /**
     * GetInstance for Singleton pattern
     *
     * @return VestaService
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new VestaService();
        }

        return self::$instance;
    }

    /**
     * @param null $wsdl
     * @param null $username
     * @param null $password
     * @param null $domain
     */
    public function setClient($wsdl = null, $username = null, $password = null, $domain = null)
    {
        $wsdl = $wsdl ?: env('VESTA_ENDPOINT');
        if (!$wsdl) {
            throw new \SoapFault('WSDL', ('The path or URL to the SOAP WSDL has not been set.'));
        }

        if (substr($wsdl, -4) !== 'wsdl') {
            throw new \SoapFault('WSDL', ('The path or URL to the SOAP WSDL should end with \'wsdl\'.'));
        }

        $this->client = new \SoapClient($wsdl, [
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        ]);

        $this->username = $username ?: env('VESTA_USER');
        $this->password = $password ?: env('VESTA_PASSWORD');
        $this->domain = $domain ?: env('VESTA_USER_DOMAIN');
    }

    /**
     * Get the security object needed to create a Vesta connection
     * @return stdClass with correct cred format
     */
    protected function getSecurity()
    {
        $security = new \stdClass();
        $security->Domain = base64_encode($this->domain);
        $security->Username = base64_encode($this->username);
        $security->Password = base64_encode($this->password);

        return $security;
    }

    /**
     * @return mixed
     */
    protected function getActionParams()
    {
        $parameters = new \stdClass();
        $parameters->cred = $this->getSecurity();

        return $parameters;
    }

    /**
     * Update Openinghours in VESTA
     *
     * Assemble Soap call FillHours
     * Check response for failing
     * Return boolean for success
     *
     * @param  string $vestaUid
     * @param  string $output
     * @return boolean
     */
    public function updateOpeninghours($guid, $hours = '')
    {
        if (!$guid) {
            throw new \Exception('A guid is required to update the data in VESTA');
        }
        $parameters = $this->getActionParams();
        $parameters->accountId = $guid;
        $parameters->hours = $hours;

        $response = $this->getClient()->FillHours($parameters);
        if (!isset($response->FillHoursResult)) {
            \Log::error('Something went wrong in VESTA.', [
                'response' => print_r($response, 1),
            ]);

            return false;
        }

        $fillHoursResult = json_decode($response->FillHoursResult);
        if ($fillHoursResult !== 1) {
            \Log::error('Something went wrong while writing the data to VESTA.', [
                'response' => $fillHoursResult,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Get the data out of VESTA
     * Only usefull as doublec heck
     * returns the ves_openingsuren of the
     *
     * @param $guid
     * @return mixed
     */
    public function getOpeningshoursByGuid($guid)
    {
        if (!$guid) {
            throw new \Exception('A guid is required to request the data from VESTA');
        }
        $filterRule = new \stdClass();
        $filterRule->Data = $guid;
        $filterRule->Field = 'accountid';

        $filters = new \stdClass();
        $filters->Rules[] = $filterRule;

        $search = $this->getActionParams();
        $search->tableName = 'account';
        $search->filters = $filters;

        $result = $this->getClient()->SearchJSON($search);

        if (!isset($result->SearchJSONResult)) {
            return false;
        }
        $result = json_decode($result->SearchJSONResult);

        if ($result->Total !== 1) {
            return false;
        }

        return $result->Rows[0]->ves_openingsuren;
    }

    /**
     * Lazily initialize the soap client.
     *
     * @return \SoapClient
     */
    protected function getClient() {
        if (!$this->client) {
            $this->setClient();
        }

        return $this->client;
    }
}
