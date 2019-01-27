<?php

require 'vendor/autoload.php';
chdir(__DIR__);

use Dotenv\Dotenv;
use StoryPoint\Statistics;

$env = new Dotenv(__DIR__);
$env->load();


$statistics = new Statistics();
$timeEntries = $statistics->getToggleTimeEntriesByWeekSummedUp();


echo 'Overtime per workweek, UTC+0 timezone, ' . count($timeEntries) . ' weeks:<hr>';
echo '<table>';

echo
    '<tr>' .
    '<th>Workweek</th>' .
    '<th>Toggl entries, count</th>' .
    '<th>Total, in seconds</th>' .
    '<th>Total, in minutes</th>' .
    '<th>Total, in hours</th>' .
    '<th>Official working days, LT</th>' .
    '<th>Official working hours, LT</th>' .
    '<th>Diff (Worked - Official)</th>' .
    '<th>Working hours load, %</th>' .
    '</tr>';

$summableFrom = getenv('OVERTIME_SUMMABLE_FROM');
$startCounting = false;
$summableWorkedMinutes = 0;
$summableOfficialMinutes = 0;
$totalWorkingHoursLoad = 0;

foreach ($timeEntries as $workweekKey => $workweekData) {

    $style = '';

    // For Google sheets
    echo
        '<tr>' .
        '<td ' . $style . '>' . $workweekKey . '</td>' .
        '<td ' . $style . '>' . count($workweekData['items']) . '</td>' .
        '<td ' . $style . '>' . $workweekData['total_seconds'] . '</td>' .
        '<td ' . $style . '>' . $workweekData['total_minutes'] . '</td>' .
        '<td ' . $style . '>' . round($workweekData['total_minutes'] / 60, 0) . '</td>' .
        '<td ' . $style . '>' . $workweekData['officialWorkingDaysLt'] . '</td>' .
        '<td ' . $style . '>' . $workweekData['officialWorkingHoursLt'] . '</td>' .
        '<td ' . $style . '>' . round(($workweekData['total_minutes'] - ($workweekData['officialWorkingHoursLt'] * 60)) / 60, 0) . '</td>' .
        '<td ' . $style . '>' . round($workweekData['total_minutes'] / ($workweekData['officialWorkingHoursLt'] * 60) * 100, 0) . '</td>' .
        '</tr>';

    if ($startCounting || $summableFrom === $workweekKey) {
        $startCounting = true;
        $summableWorkedMinutes += $workweekData['total_minutes'];
        $summableOfficialMinutes += $workweekData['officialWorkingHoursLt'] * 60;
    }

    $totalWorkingHoursLoad += round($workweekData['total_minutes'] / ($workweekData['officialWorkingHoursLt'] * 60) * 100, 0);

}

echo '<table>';

echo '<br>';
echo '<p>Overtime since start of the week "' . $summableFrom . '" up to datetime "' . getenv('TOGGL_ENTRIES_TO') . '":<br>'
    . 'Worked minutes (' . $summableWorkedMinutes . ') - Official minutes (' . $summableOfficialMinutes . ') = ' . ($summableWorkedMinutes - $summableOfficialMinutes) . ' minutes = ' . round(($summableWorkedMinutes - $summableOfficialMinutes) / 60, 2) . ' hours'
    . '</p>';

echo '<p>Average working hours load: ' . round($totalWorkingHoursLoad / count($timeEntries), 2) . '% ';
echo ' = ' . round(($totalWorkingHoursLoad / count($timeEntries)) * 40 / 100, 2) . ' hours per week</p>';


