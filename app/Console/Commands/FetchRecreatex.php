<?php

namespace App\Console\Commands;

use App\Events\OpeninghoursUpdated;
use App\Models\Calendar;
use App\Models\Channel;
use App\Models\Event;
use App\Models\Openinghours;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FetchRecreatex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openinghours:fetch-recreatex';

    /**
     * The SHOP ID for the RECREATEX service
     *
     * @var string
     */
    protected $shopId;

    /**
     * The Recreatex SOAP URI
     *
     * @var string
     */
    protected $recreatexUri;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch RECREATEX openinghours data';

    /**
     * The start of the calendar
     * @var string
     */
    const CALENDAR_START = '2017';

    /**
     * The end of the calendar
     * @var string
     */
    const CALENDAR_END = '2020';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->shopId = env('SHOP_ID');
        $this->recreatexUri = env('RECREATEX_URI');

        if (empty($this->shopId)) {
            \Log::error("No shop ID was found, we can't fetch openinghours from the RECREATEX webservice without it.
                You can configure a shop ID in the .env file.");
        }

        if (empty($this->recreatexUri)) {
            \Log::error("No recreatexUri was found, we can't fetch openinghours from the RECREATEX webservice without it.
                You can configure a recreatexUri in the .env file.");
        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $recreatexServices = app('ServicesRepository')->where('source', 'recreatex')->get();

        foreach ($recreatexServices as $recreatexService) {
            if (! empty($recreatexService->identifier)) {
                $channelName = 'Infrastructuur';

                if (! $this->serviceHasAutogeneratedChannel($recreatexService, $channelName)) {
                    $channel = new Channel();
                    $channel->label = $channelName;

                    // Link the channel to the service
                    $recreatexService->channels()->save($channel);

                    for ($year = self::CALENDAR_START; $year <= self::CALENDAR_END; $year++) {
                        $openinghoursList = $this->getOpeninghours($recreatexService->identifier, $year);

                        if (! empty($openinghoursList)) {
                            $openinghoursList = $this->transformOpeninghours($openinghoursList, $year);
                        }

                        if (! empty($openinghoursList)) {
                            $openinghours = new Openinghours();
                            $openinghours->active = true;
                            $openinghours->start_date = $year . '-01-01';
                            $openinghours->end_date = $year . '-12-31';
                            $openinghours->label = 'Geïmporteerde kalender ' . $openinghours->start_date . ' - ' . $openinghours->end_date;

                            // Link the openinghours to the channel
                            $channel->openinghours()->save($openinghours);

                            $calendar = new Calendar();
                            $calendar->priority = 0;
                            $calendar->closinghours = 0;
                            $calendar->label = 'Openingsuren';

                            // Link the calendar to the openinghours
                            $openinghours->calendars()->save($calendar);

                            $sequenceNumber = 1;

                            foreach ($openinghoursList as $openinghoursEvent) {
                                // Transform the days
                                $weekDays = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

                                $weekDayRrule = [];

                                foreach ($openinghoursEvent['days'] as $index => $day) {
                                    $weekDayRrule[] = $weekDays[$day];
                                }

                                $event = new Event();
                                $event->rrule = 'BYDAY=' . implode(',', $weekDayRrule) . ';FREQ=WEEKLY';
                                $event->start_date = $openinghoursEvent['start']->toIso8601String();
                                $event->end_date = $openinghoursEvent['end']->toIso8601String();
                                $event->label = $sequenceNumber;
                                $event->until = $openinghoursEvent['until']->startOfDay()->format('Y-m-d');

                                // Link event to the calendar
                                $calendar->events()->save($event);

                                $sequenceNumber++;
                            }

                            $this->info('Imported calendar for year ' . $year . ' for service ' . $recreatexService->label . ' (' . $recreatexService->identifier . ')');

                            event(new OpeninghoursUpdated($openinghours->id));
                        } else {
                            if (empty($openinghoursList)) {
                                $this->info('The service ' . $recreatexService->label . ' (' . $recreatexService->identifier . ") has no events for year $year.");
                            } else {
                                $this->info('The service ' . $recreatexService->label . ' (' . $recreatexService->identifier . ") already has a channel $channelName, therefore we did not import any openinghours for this specific service.");
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Transform a list of openinghours to a shorter list that
     * holds RRULEs on a weekly frequency as much as possible, starting
     * from a list of daily (!!) events over 1 year.
     *
     * @param  array $openinghoursList
     * @param  int   $year             The year to parse the events for
     * @return array
     */
    private function transformOpeninghours($openinghoursList, $year)
    {
        $transformedList = [];

        // Keep track of weekly recurring dates
        $weekBuffer = [];

        foreach ($openinghoursList as $openinghoursEvent) {
            $timespans = [];

            $until = '';

            // Make sure the events are daily events
            $nextEventPossible = true;

            // Recreatex tends to give the last day of the previous year even though the requests
            // asks specifically to start from the first day of the given year
            $eventDay = Carbon::createFromFormat('Y-m-d\TH:i:s', $openinghoursEvent['Date']);

            if ($eventDay->year != $year) {
                continue;
            }

            if (! empty($openinghoursEvent['From1']) && ! empty($openinghoursEvent['To1'])) {
                $fromTimestamp = $openinghoursEvent['From1'];

                if (str_contains($fromTimestamp, '00:00:00')) {
                    $from = clone $eventDay;
                    $from->startOfDay();
                } else {
                    $from = Carbon::createFromFormat('Y-m-d\TH:i:s', $fromTimestamp);
                }

                // Catch the 00:00 case, the feed is supposed to deliver daily events
                // but to make a "full day open" 2 days are passed with the second day
                // having 00:00
                $toTimestamp = $openinghoursEvent['To1'];

                if (str_contains($toTimestamp, '00:00:00')) {
                    $nextEventPossible = false;

                    $to = clone $eventDay;
                    $to->endOfDay();
                } else {
                    $to = Carbon::createFromFormat('Y-m-d\TH:i:s', $openinghoursEvent['To1']);
                }

                $key = $from->format('H:i') . '-' . $to->format('H:i');

                $timespans[$key] = $from->dayOfWeek;
                $parsedWeek = $from->weekOfYear;
                $until = clone $from;
            }

            if (! empty($openinghoursEvent['From2']) && ! empty($openinghoursEvent['To2']) && $nextEventPossible) {
                $from = Carbon::createFromFormat('Y-m-d\TH:i:s', $openinghoursEvent['From2']);
                $to = Carbon::createFromFormat('Y-m-d\TH:i:s', $openinghoursEvent['To2']);

                $key = $from->format('H:i') . '-' . $to->format('H:i');

                $timespans[$key] = $from->dayOfWeek;
                $parsedWeek = $from->weekOfYear;
                $until = $from;
            }

            // Add the timespans to the week schedule
            if (! empty($timespans)) {
                foreach ($timespans as $timespan => $day) {
                    // Week 1 and 52 can overlap, spanning a year
                    // keep them separate and add them as daily events
                    if ($parsedWeek == 52 || $parsedWeek == 1) {
                        $transformedList[] = [
                            'days' => [$day],
                            'start' => clone $from,
                            'end' => clone $to,
                            'until' => clone $eventDay
                        ];
                    } else {
                        if (empty($weekBuffer[$parsedWeek]['schedules'])) {
                            $weekBuffer[$parsedWeek] = [];
                            $weekBuffer[$parsedWeek]['schedules'] = [];
                        }

                        if (empty($weekBuffer[$parsedWeek]['schedules'][$timespan])) {
                            $weekBuffer[$parsedWeek]['schedules'][$timespan] = ['days' => [$day], 'start' => clone $from, 'end' => clone $to, 'until' => clone $eventDay];
                        } else {
                            $weekBuffer[$parsedWeek]['schedules'][$timespan]['days'][] = $day;
                            $days = $weekBuffer[$parsedWeek]['schedules'][$timespan]['days'];
                            $days = array_unique($days);

                            $weekBuffer[$parsedWeek]['schedules'][$timespan]['days'] = $days;
                            $weekBuffer[$parsedWeek]['schedules'][$timespan]['until'] = clone $eventDay;
                        }

                        $weekBuffer[$parsedWeek]['until'] = clone $eventDay;
                    }
                }
            }
        }

        // All year closed
        if (empty($weekBuffer)) {
           return [];
        }

        $firstWeek = key($weekBuffer);

        // Parse weekly RRULEs from the week schedules
        $recurringBuffer = $weekBuffer[$firstWeek]['schedules'];
        unset($weekBuffer[$firstWeek]);

        foreach ($weekBuffer as $weekNumber => $weekConfig) {
            $schedule = $weekConfig['schedules'];
            $until = $weekConfig['until'];

            $newBuffer = [];

            foreach ($schedule as $timespan => $timespanConfig) {
                if (array_key_exists($timespan, $recurringBuffer) && array_values($timespanConfig['days']) == array_values($recurringBuffer[$timespan]['days'])) {
                    $recurringBuffer[$timespan]['until'] = $until;
                } else {
                    $newBuffer[$timespan] = $timespanConfig;
                }
            }

            // Filter out the events that did not receive an updated until date
            $tmpBuffer = [];

            foreach ($recurringBuffer as $timespan => $config) {
                if ($config['until']->toDateString() != $until->toDateString()) {
                    $transformedList[] = $config;
                } else {
                    $tmpBuffer[$timespan] = $config;
                }
            }

            $recurringBuffer = $tmpBuffer;

            // Merge the recurring rules with the new timespans
            // if timespans overlap, this means that the old rule should be put
            // into the final list of events as well
            foreach ($newBuffer as $timespan => $config) {
                if (array_key_exists($timespan, $recurringBuffer)) {
                    $transformedList[] = $config;

                }

                $recurringBuffer[$timespan] = $config;
            }
        }

        foreach ($recurringBuffer as $timespan => $config) {
            $transformedList[] = $config;
        }

        return $transformedList;
    }

    /**
     * Determine if the service has a channel called "Infrastructuur"
     *
     * @param  Eloquent $service
     * @param  string   $channelName
     * @return boolean
     */
    private function serviceHasAutogeneratedChannel($service, $channelName)
    {
        $channels = $service->channels;

        if (empty($channels)) {
            return false;
        }

        return $channels->contains(function ($value) use ($channelName) {
            return $value->label == $channelName;
        });
    }

    /**
     * Fetch the recreatex openinghours for a certain infrastructure
     *
     * @param  string $recreatexId
     * @param  int    $year
     * @return array
     */
    private function getOpeninghours($recreatexId, $year)
    {
        $soapBody = $this->makeSoapBody($recreatexId, $year);

        $headers = [
            'Content-type: text/xml;charset="utf-8"',
            'Accept: text/xml',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'SOAPAction:  http://www.recreatex.be/webshop/v3.8/IWebShop/FindInfrastructureOpenings',
            'Content-length: ' . strlen($soapBody),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL, $this->recreatexUri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $soapBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        curl_close($ch);

        // Remove the SOAP envelop
        $response = str_replace('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>', '', $response);
        $response = str_replace('</s:Body></s:Envelope>', '', $response);

        $xml = simplexml_load_string($response);
        $json = json_encode($xml);
        $fullJson = json_decode($json, true);

        // Parse the InfrastructureOpeningHours from the body
        return array_get($fullJson, 'InfrastructureOpeningHours.InfrastructureOpeningHours.OpenHours.OpeningHour', []);
    }

    private function makeSoapBody($recreatexId, $year)
    {
        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:v3="http://www.recreatex.be/webshop/v3.8/">
                   <soapenv:Header/>
                   <soapenv:Body>
                      <v3:Context>
                         <v3:ShopId>' . $this->shopId . '</v3:ShopId>
                      </v3:Context>
                      <v3:InfrastructureOpeningsSearchCriteria>
                         <v3:InfrastructureId>' . $recreatexId . '</v3:InfrastructureId>
                         <v3:From>' . $year . '-01-01T00:00:00.8115784+02:00</v3:From>
                         <v3:Until>' . ++$year . '-01-01T00:00:00.8115784+02:00</v3:Until>
                      </v3:InfrastructureOpeningsSearchCriteria>
                   </soapenv:Body>
                </soapenv:Envelope>';
    }
}
