<?php


namespace TimeMatrix;


use MorningTrain\TogglApi\TogglApi;

class Week extends TimeMatrix
{
    private const WEEK_INDEXES_STARTS_AT = 0;
    private const WEEK_INDEXES_ENDS_AT = 10079; // (60 mins x 24 hours x 7 days) - 1 (because we want indexes to start from 00:00 of monday)


    public function __construct()
    {
        $this->setMatrix(static::WEEK_INDEXES_STARTS_AT, static::WEEK_INDEXES_ENDS_AT);
    }

    public static function getNextMinuteFromKeysOfMatrix(array $humanizedWorkweekMatrix): int
    {
        $currentMinute = static::getCurrentMinuteOfTheWeekUtc0();
        $matrixMinutes = array_keys($humanizedWorkweekMatrix);

        foreach ($matrixMinutes as $minute) {
            if ($minute > $currentMinute) {
                return $minute;
            }
        }

        // It is out of range [first-last] of the matrix, returning the first item because the last one passed by already.
        return reset($matrixMinutes);
    }

    public static function getCurrentMinuteOfTheWeekUtc0(): int
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        //$now = new \DateTime('2018-10-22 06:20:52', new \DateTimeZone( 'UTC' ));


        $dayOfWeekOfNow = $now->format('w');
        switch ($dayOfWeekOfNow) {
            case 1: // Monday
                $timeNowMonday = clone $now;
                break;
            case 2: // Tuesday
            case 3: // Wednesday
            case 4: // Thursday
            case 5: // Friday
            case 6: // Saturday
                $timeNowMonday = clone $now;
                $timeNowMonday->sub(new \DateInterval(sprintf('P%sD', $dayOfWeekOfNow - 1)));
                break;
            case 0: // Sunday
                $timeNowMonday = clone $now;
                $timeNowMonday->sub(new \DateInterval(sprintf('P%sD', 6)));
                break;

            default:
                throw new \RuntimeException(sprintf('Bad day of the week, got "%s"', $dayOfWeekOfNow));
                break;
        }

        $timeNowMonday00 = new \DateTime($timeNowMonday->format('Y-m-d\T00:00:00O'));

        $interval = $now->diff($timeNowMonday00);
        $minutesFromNowMonday00 = ($interval->d * 24 * 60) + ($interval->h * 60) + ($interval->i);

        return $minutesFromNowMonday00;
    }

    public static function getHumanizedMatrix(array $weekMatrixResult): array
    {
//        if ($customMatrix !== null) {
//            $weekMatrixResult = $customMatrix;
//        } else {
//            $weekMatrixResult = $this->matrix;
//        }

        array_walk($weekMatrixResult, function (&$occurrencesCount, $minute) {

            $humanizedTime = '';

            $hours = (int)floor(($minute - (floor($minute / 60 / 24) * 60 * 24)) / 60);
            $minutes = (int)($minute - floor($minute / 60 / 24) * 24 * 60 - floor(($minute - (floor($minute / 60 / 24) * 60 * 24)) / 60) * 60);

            if ($minute >= 0 && $minute <= (60 * 24 * 1 - 1)) {
                $humanizedTime .= sprintf('Monday at %02d:%02d', $hours, $minutes);
            } elseif ($minute >= (60 * 24 * 1) && $minute <= (60 * 24 * 2 - 1)) {
                $humanizedTime .= sprintf('Tuesday at %02d:%02d', $hours, $minutes);
            } elseif ($minute >= (60 * 24 * 2) && $minute <= (60 * 24 * 3 - 1)) {
                $humanizedTime .= sprintf('Wednesday at %02d:%02d', $hours, $minutes);
            } elseif ($minute >= (60 * 24 * 3) && $minute <= (60 * 24 * 4 - 1)) {
                $humanizedTime .= sprintf('Thursday at %02d:%02d', $hours, $minutes);
            } elseif ($minute >= (60 * 24 * 4) && $minute <= (60 * 24 * 5 - 1)) {
                $humanizedTime .= sprintf('Friday at %02d:%02d', $hours, $minutes);
            } elseif ($minute >= (60 * 24 * 5) && $minute <= (60 * 24 * 6 - 1)) {
                $humanizedTime .= sprintf('Saturday at %02d:%02d', $hours, $minutes);
            } elseif ($minute >= (60 * 24 * 6) && $minute <= (60 * 24 * 7 - 1)) {
                $humanizedTime .= sprintf('Sunday at %02d:%02d', $hours, $minutes);
            } else {
                throw new \RuntimeException(sprintf('Unknown minutes: %s', $minute));
            }

            $occurrencesCount = sprintf('%s, Minute: %s, Occurrences: %s', $humanizedTime, $minute, $occurrencesCount);

        });

        return $weekMatrixResult;

    }

    public function getMatrixOfActualWorkweekMinutesSortedByMinutesAsc(): array
    {
        $toggl = new TogglApi(getenv('TOGGL_API_TOKEN'));
        $entries = $toggl->getTimeEntriesInRange(getenv('TOGGL_ENTRIES_FROM'), getenv('TOGGL_ENTRIES_TO'));


        // Statistics on each minute of workweek days
        //$weekMatrix = new Week();
        $this->composeMatrix($entries);


        // Statistics on each minute of workweek days
        return $this->getFattySliceOfSortedMatrix((int)getenv('WORKING_WEEK_LENGTH_IN_MINUTES'));
    }

    public function composeMatrix(array $timeEntries): void
    {
        // Check for a cached file (we may get data from there)
        $pathToCacheFile = $this->makePathToCacheFile();
        if (is_file($pathToCacheFile)) {
            $this->setMatrix(static::WEEK_INDEXES_STARTS_AT, static::WEEK_INDEXES_ENDS_AT, json_decode(file_get_contents($pathToCacheFile)));
            return;
        }

        // Generate new matrix
        foreach ($this->matrix as $minute => $count) {
            foreach ($timeEntries as $timeEntry) {
                if ($this->isMinuteInTimeEntry($minute, new \DateTime($timeEntry->{static::INDEX_TIME_START}), new \DateTime($timeEntry->{static::INDEX_TIME_STOP}))) {
                    $this->matrix[$minute]++;
                }
            }
        }

        // Save the newly generated matrix to a cache file
        file_put_contents($pathToCacheFile, json_encode($this->getMatrix()));
    }

    private function makePathToCacheFile(): string
    {
        return getenv('TOGGLE_CACHE_DIR_WEEK') . DIRECTORY_SEPARATOR . md5(sprintf('%s_%s_%s',
                    md5(getenv('TOGGL_API_TOKEN')),
                    md5(getenv('TOGGL_ENTRIES_FROM')),
                    md5(getenv('TOGGL_ENTRIES_TO')))
            );
    }

    /**
     * Checks if the range given ($timeStart - $timeStop) falls under the given minute of a week.
     * "The week" is a week in which the $timeStop occurs.
     * Only fully worked minutes (entire 60 seconds) counts in.
     *
     * @param int $minute
     * @param \DateTime $timeStart
     * @param \DateTime $timeStop
     * @return bool
     * @throws \Exception
     */
    private function isMinuteInTimeEntry(int $minute, \DateTime $timeStart, \DateTime $timeStop): bool
    {
        $dayOfWeekOfStopTime = $timeStop->format('w');
        switch ($dayOfWeekOfStopTime) {
            case 1: // Monday
                $timeStopMonday = clone $timeStop;
                break;
            case 2: // Tuesday
            case 3: // Wednesday
            case 4: // Thursday
            case 5: // Friday
            case 6: // Saturday
                $timeStopMonday = clone $timeStop;
                $timeStopMonday->sub(new \DateInterval(sprintf('P%sD', $dayOfWeekOfStopTime - 1)));
                break;
            case 0: // Sunday
                $timeStopMonday = clone $timeStop;
                $timeStopMonday->sub(new \DateInterval(sprintf('P%sD', 6)));
                break;

            default:
                throw new \RuntimeException(sprintf('Bad day of the week, got "%s"', $dayOfWeekOfStopTime));
                break;
        }

        // Monday 00:00:00 of the time stop
        $timeStopMonday00 = new \DateTime($timeStopMonday->format('Y-m-d\T00:00:00O'));

        $timeStart00Seconds = new \DateTime($timeStart->format('Y-m-d\TH:i:00O'));
        $timeStop00Seconds = new \DateTime($timeStop->format('Y-m-d\TH:i:00O'));

        // Monday 00:00:00 of the time stop + minutes
        $timeStopMonday00PlusMinutes = clone $timeStopMonday00;
        if ($minute !== 0) {
            $timeStopMonday00PlusMinutes->modify("+{$minute} minutes"); // aka. "DateTime value for $minutes on this range case"
        }

        // IF 2018-05-16 05:34:06.000000 is start dateTime and 2018-05-16 05:34:00.000000 is "minutes dateTime" then it is FALSE
        // IF 2018-05-16 05:34:06.000000 is start dateTime and 2018-05-16 05:35:00.000000 is "minutes dateTime" then it is TRUE
        // IF 2018-05-16 09:09:45.000000 is end   dateTime and 2018-05-16 09:08:00.000000 is "minutes dateTime" then it is TRUE
        // IF 2018-05-16 09:09:45.000000 is end   dateTime and 2018-05-16 09:09:00.000000 is "minutes dateTime" then it is FALSE
        // IF 2018-05-16 09:09:45.000000 is end   dateTime and 2018-05-16 09:10:00.000000 is "minutes dateTime" then it is FALSE
        // IF minutes fall in between of START-STOP, it is always TRUE.
        // IF minutes fall    outside of START-STOP, it is always FALSE.
        $minuteIsIn = false;
        //if ($timeStart <= $timeStopMonday00PlusMinutes && $timeStopMonday00PlusMinutes <= $timeStop) {
        if ($timeStart00Seconds < $timeStopMonday00PlusMinutes && $timeStopMonday00PlusMinutes < $timeStop00Seconds) {
            $minuteIsIn = true;
        }

        unset($timeStart00Seconds, $timeStop00Seconds, $timeStopMonday00PlusMinutes, $timeStopMonday00, $timeStopMonday, $dayOfWeekOfStopTime);

        return $minuteIsIn;
    }

}