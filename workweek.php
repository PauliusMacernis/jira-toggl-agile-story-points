<?php

require 'vendor/autoload.php';
chdir(__DIR__);

//const WORKING_WEEK_LENGTH_IN_MINUTES = 5*8*60; // 5 days by 8 hours by 60 minutes per hour

// https://packagist.org/packages/lesstif/php-jira-rest-client

use Dotenv\Dotenv;
use TimeMatrix\Week;

$env = new Dotenv(__DIR__);
$env->load();


$weekMatrix = new Week();
$fattySliceOfSortedMatrix = $weekMatrix->getMatrixOfActualWorkweekMinutesSortedByMinutesAsc();
$humanizedWorkweekMatrix = Week::getHumanizedMatrix($fattySliceOfSortedMatrix);
$nextMinuteInMatrix = Week::getNextMinuteFromKeysOfMatrix($humanizedWorkweekMatrix);


echo 'Working minutes for a workweek, UTC+0 timezone, ' . count($humanizedWorkweekMatrix) . ' items:<hr>';
echo '<table>';

foreach ($humanizedWorkweekMatrix as $workweekMinute => $row) {

    $style = '';
    if ($workweekMinute === $nextMinuteInMatrix) {
        $style = 'style="background-color: orange;"';
    }

    // For Google sheets
    $dataParts = explode(', ', $row);
    echo '<tr><td ' . $style . '>' . $dataParts[0] . '</td><td ' . $style . '>' . explode(': ', $dataParts[1])[1] . '</td><td ' . $style . '>' . explode(': ', $dataParts[2])[1] . '</td></tr>';

    // For simple output
    //echo '<tr><td>' . $row . '</td></tr>';
}
echo '<table>';
