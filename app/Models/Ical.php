<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use \ICal\ICal as ICalParser;

/**
 * Model to keep specific Ical object per Openinghours
 * Can be called by openinghours->ical()
 * Is bound with IcalParser
 * Uses limited Ical string date range to limit expensive overeagerness of parser *
 */
class Ical
{
    /**
     * @var \ICal\ICal
     */
    private $parser;

    /**
     * @var string
     */
    private $icalString;

    /**
     * @var Collection
     */
    private $calendars;

    /**
     * @param Collection $calendars
     */
    public function __construct(Collection $calendars)
    {
        $calendars = array_sort($calendars, function ($calendar) {
            return $calendar['priority'];
        });
        $this->calendars = $calendars;

        return $this;
    }

    /**
     * Create parser within from until range
     *
     * For performance reasons it is better to init the parser only
     * for the from until periode that will be needed
     * The parser does a processDateConversions
     * that converts all the dates and this is expensive
     *
     * @param Carbon   $from
     * @param Carbon   $until
     *
     * @return ICal
     */
    public function initParser()
    {
        $this->parser = new ICalParser();
        $this->parser->initString($this->icalString);
    }

    /**
     * @param Carbon $from
     * @param Carbon $till
     */
    public function createIcalString(Carbon $from, Carbon $till, $initParser = true)
    {
        $this->icalString = "BEGIN:VCALENDAR" . PHP_EOL . "VERSION:2.0" . PHP_EOL . "CALSCALE:GREGORIAN" . PHP_EOL;

        foreach ($this->calendars as $calendar) {
            $this->icalString .= $this->createIcalEventStringFromCalendar($calendar, $from, $till);
        }

        $this->icalString .= 'END:VCALENDAR';
        if ($initParser) {
            $this->initParser();
        }

        return $this;
    }

    /**
     * @return ICal string
     */
    public function getIcalString()
    {
        return $this->icalString;
    }

    /**
     * Create an ICAL string from a calendar
     *
     * @param  Calendar    $calendar     [description]
     * @param  Carbon|null $minTimestamp [description]
     * @param  Carbon|null $maxTimestamp [description]
     * @return [type]                    [description]
     */
    protected function createIcalEventStringFromCalendar(
        Calendar $calendar,
        Carbon $minTimestamp,
        Carbon $maxTimestamp
    ) {
        $icalString = '';

        foreach ($calendar->events as $event) {
            $until = new Carbon($event->until);
            $until->endOfDay();

            $startDate = new Carbon($event->start_date);
            $endDate = new Carbon($event->end_date);

            if ($startDate->greaterThan($maxTimestamp) || $until->lessThan($minTimestamp)) {
                continue;
            }

            if ($until->greaterThan($maxTimestamp) && $maxTimestamp->greaterThan($startDate)) {
                $until = $maxTimestamp;
            }

            $status = 'OPEN';
            if ($calendar->closinghours === 1) {
                $status = 'CLOSED';
                $startDate->hour = 0;
                $startDate->minute = 0;
                $endDate->hour = 23;
                $endDate->minute = 59;
            }

            $icalString .= "BEGIN:VEVENT" . PHP_EOL;
            $icalString .= 'SUMMARY:' . $calendar->label . PHP_EOL;
            $icalString .= 'STATUS:' . $status . PHP_EOL;
            $icalString .= 'PRIORITY:' . ($calendar->priority + 20) . PHP_EOL;
            $icalString .= 'DTSTART:' . $startDate->format('Ymd\THis') . PHP_EOL;
            $icalString .= 'DTEND:' . $endDate->format('Ymd\THis') . PHP_EOL;
            $icalString .= 'DTSTAMP:' . Carbon::now()->format('Ymd\THis') . 'Z' . PHP_EOL;
            $icalString .= 'RRULE:' . $event->rrule . ';UNTIL=' . $until->format('Ymd\THis') . PHP_EOL;
            $icalString .= 'UID:' . 'PRIOR_' . ((int) $calendar->priority + 99) . '_' . $status . '_CAL_' ;
            $icalString .=  $calendar->id . PHP_EOL;
            $icalString .= "END:VEVENT" . PHP_EOL;
        }

        return $icalString;
    }

    /**
     * Check if there are events for a given day
     * Attr openNow respects the given time and adds an extra minute
     *
     * @param  Carbon  $date    [description]
     * @param  boolean $openNow [description]
     * @return [type]           [description]
     */
    public function getOpenAt(Carbon $date = null)
    {
        if (null === $date) {
            $date = new Carbon();
        }
        $startDate = $date->copy()->startOfDay();
        $endDate = $date->copy()->endOfDay();
        $datePeriod = new \DatePeriod($startDate, new \DateInterval('P1D'), $endDate);
        // Get the info for today.
        $data = $this->getPeriodInfo($datePeriod);
        $today = reset($data);
        if ($today->open === false) {
            return false;
        }
        foreach ($today->hours as $slot) {
            $opens = [];
            $closes = [];
            list($opens['h'], $opens['i']) = explode(':', $slot['from']);
            list($closes['h'], $closes['i']) = explode(':', $slot['until']);
            $opensCarbon = $date->copy()->setTime(intval($opens['h']), intval($opens['i']), 0);
            $closesCarbon = $date->copy()->setTime(intval($closes['h']), intval($closes['i']), 0);
            if ($date->between($opensCarbon, $closesCarbon)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if there are events for a given day
     * Attr openNow respects the given time and adds an extra minute
     *
     * @param  DatePeriod  $datePeriod    [description]
     * @return DayInfo[]                  [description]
     */
    public function getPeriodInfo(\DatePeriod $datePeriod)
    {
        $events = $this->getEvents($datePeriod);
        $data = [];
        // Prefill data.
        foreach ($datePeriod as $day) {
            $carbonDay = Carbon::instance($day);
            $dayInfo = new DayInfo($carbonDay);
            $data[$carbonDay->toDateString()] = $dayInfo;
        }
        foreach ($events as $event) {
            $start = $event->dtstart;
            $end = $event->dtend;
            $dtStart = Carbon::createFromFormat('Ymd\THis', $start);
            $dtEnd = Carbon::createFromFormat('Ymd\THis', $end);
            if (!isset($data[$dtStart->toDateString()]) || $data[$dtStart->toDateString()]->open === false) {
                continue;
            }

            $dayInfo = $data[$dtStart->toDateString()];
            $dayInfo->open = ($event->status === 'OPEN');
            if ($dayInfo->open) {
                $dayInfo->hours[] = ['from' => $dtStart->format('H:i'), 'until' => $dtEnd->format('H:i')];
            }
        }
        foreach ($datePeriod as $day) {
            $carbonDay = Carbon::instance($day);
            if ($data[$carbonDay->toDateString()]->open === null) {
                $data[$carbonDay->toDateString()]->open = false;
            }
        }
        return $data;
    }

    /**
     * Parse all events from the ical string for a given period
     * @param \DatePeriod $datePeriod
     *
     * @return \ICal\Event[]
     */
    protected function getEvents(\DatePeriod $datePeriod)
    {
        $startDate = new Carbon($datePeriod->getStartDate());
        $endDate = new Carbon($datePeriod->getEndDate());

        if (empty($this->icalString)) {
            $this->initParser($startDate, $endDate);
        }

        $events = $this->parser->eventsFromRange($startDate, $endDate);
        usort($events, [$this, 'sortEvents']);
        return $events;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->icalString;
    }

    public function sortEvents(\ICal\Event $a, \ICal\Event $b)
    {
        $result = $a->priority - $b->priority;
        if ($result !== 0) {
            return $result;
        }
        $aStart = Carbon::createFromFormat('Ymd\THis', $a->dtstart);
        $bStart = Carbon::createFromFormat('Ymd\THis', $b->dtstart);
        if ($aStart > $bStart || $aStart < $bStart) {
            return $aStart > $bStart;
        }

        $aEnd = Carbon::createFromFormat('Ymd\THis', $a->dtend);
        $bEnd = Carbon::createFromFormat('Ymd\THis', $b->dtend);
        return $aEnd > $bEnd;
    }
}