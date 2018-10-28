<?php


namespace TimeMatrix;


use MorningTrain\TogglApi\TogglApi;

class Day extends TimeMatrix
{
    private const HOUR_INDEXES_STARTS_AT = 0;
    private const HOUR_INDEXES_ENDS_AT = 23;


    public function __construct()
    {
        $this->setMatrix(static::HOUR_INDEXES_STARTS_AT, static::HOUR_INDEXES_ENDS_AT);
    }

    public static function getHumanizedMatrix(array $dayMatrixResult): array
    {
//        if ($customMatrix !== null) {
//            $dayMatrixResult = $customMatrix;
//        } else {
//            $dayMatrixResult = $this->matrix;
//        }

        array_walk($dayMatrixResult, function (&$occurrencesCount, $hour) {

            $occurrencesCount = sprintf('%02d, %s', $hour, $occurrencesCount);

        });

        return $dayMatrixResult;

    }

    public function getMatrixOfActualWorkdaySortedByHoursAsc(): array
    {
        $toggl = new TogglApi(getenv('TOGGL_API_TOKEN'));
        $entries = $toggl->getTimeEntriesInRange(getenv('TOGGL_ENTRIES_FROM'), getenv('TOGGL_ENTRIES_TO'));

        // Statistics on day hours
        //$dayMatrix = new Day();
        $this->composeMatrix($entries);

        return $this->getFattySliceOfSortedMatrix(24); // 24 hours in a day
    }

    public function composeMatrix(array $timeEntries): void
    {
        foreach ($this->matrix as $hour => $count) {
            foreach ($timeEntries as $timeEntry) {
                if ($this->isHourInTimeEntry($hour, new \DateTime($timeEntry->{static::INDEX_TIME_START}), new \DateTime($timeEntry->{static::INDEX_TIME_STOP}))) {
                    $this->matrix[$hour]++;
                }
            }
        }
    }

    /**
     * Checks if the range given ($timeStart - $timeStop) falls under the given hour of a day
     * "The day" is a day in which the $timeStop occurs.
     * Only fully worked hours (entire 60 minutes) counts in.
     *
     * @param int $hour
     * @param \DateTime $timeStart
     * @param \DateTime $timeStop
     * @return bool
     * @throws \Exception
     */
    private function isHourInTimeEntry(int $hour, \DateTime $timeStart, \DateTime $timeStop): bool
    {

        $timeStart00Minutes = new \DateTime($timeStart->format('Y-m-d\TH:00:00O'));
        $timeStop00Minutes = new \DateTime($timeStop->format('Y-m-d\TH:00:00O'));
        $timeStop00PlusHours = new \DateTime($timeStop->format('Y-m-d\T' . sprintf('%02d', $hour) . ':00:00O'));

        $hoursIsIn = false;
        if ($timeStart00Minutes < $timeStop00PlusHours && $timeStop00PlusHours < $timeStop00Minutes) {
            $hoursIsIn = true;
        }

        unset($timeStart00Minutes, $timeStop00Minutes, $timeStop00PlusHours);

        return $hoursIsIn;
    }

}