<?php

namespace App\Formatters;

use App\Formatters\Openinghours\BaseFormatter;
use App\Models\Service;

/**
 * Formatter class for Openinghours
 * renders given data into given format
 */
class OpeninghoursFormatter implements EndPointFormatterInterface
{
    /**
     * contains the uri of the active record service
     * @var string
     */
    private $service;

    /**
     * @var array
     */
    private $formatters = [];

    /**
     * Adds format to endpointformatter
     *
     * Checksor format can be found in the correct namespace
     *
     * @param $formatter
     * @return $this
     */
    public function addFormat($formatter)
    {
        if (!($formatter instanceof BaseFormatter)) {
            throw new \Exception($formatter . " is not supported as format for " . self::class, 1);
        }
        $this->formatters[] = $formatter;

        return $this;
    }

    /**
     * Return all supported formats of this endpoint
     *
     * @return array
     */
    public function getFormatters()
    {
        return $this->formatters;
    }

    /**
     * @param Service $service
     */
    public function setService(Service $service)
    {
        $this->service = $service;
    }

    /**
     * Render data according to the given format
     *
     * @param  string $format to match with available formats
     * @param  array $data   data to transform
     * @return mixed         formatted data
     */
    public function render($format, $data)
    {
        if (!$data) {
            throw new \Exception("No data given for formatter" . self::class, 1);
        }

        $activeFormatter = null;
        foreach ($this->formatters as $formatter) {
            if ($formatter->getSupportFormat() === $format) {
                $activeFormatter = $formatter;
                $activeFormatter->service = $this->service;
                break;
            }
        }
        if (!$activeFormatter) {
            throw new \Exception("The given format " . $format . " is not available in " . self::class, 1);
        }

        return $activeFormatter->render($data)->getOutput();
    }

}
