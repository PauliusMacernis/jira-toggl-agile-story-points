<?php

require 'vendor/autoload.php';
chdir(__DIR__);

// https://packagist.org/packages/lesstif/php-jira-rest-client

use Dotenv\Dotenv;
use TimeMatrix\Day;

$env = new Dotenv(__DIR__);
$env->load();


$dayMatrix = new Day();
$fattySliceOfSortedMatrix = $dayMatrix->getMatrixOfActualWorkdaySortedByHoursAsc();
$humanizedDayMatrix = Day::getHumanizedMatrix($fattySliceOfSortedMatrix);

echo 'Working hours for a day, UTC+0 timezone, ' . count($humanizedDayMatrix) . ' items:<hr>';
echo '<table>';
foreach ($humanizedDayMatrix as $row) {
    // For Google sheets
    $dataParts = explode(', ', $row);
    echo '<tr><td>' . $dataParts[0] . '</td><td>' . $dataParts[1] . '</td></tr>';

    // For simple output
    //echo '<tr><td>' . $row . '</td></tr>';
}
echo '<table>';
