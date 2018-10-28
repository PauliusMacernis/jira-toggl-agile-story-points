<?php

require 'vendor/autoload.php';
chdir(__DIR__);


use Dotenv\Dotenv;

$env = new Dotenv(__DIR__);
$env->load();

$storyPointStatistics = new \StoryPoint\Statistics();
$data = $storyPointStatistics->getStatisticsData(['Done', 'QA'], false);


echo 'Average time spent on the story point size:'
    . '<hr>';

echo '<table>';

echo '<tr>'
    . '<th>' . 'Story point' . '</th>'
    . '<th>' . 'Average weight in seconds' . '</th>'
    . '<th>' . 'Average weight in minutes' . '</th>'
    . '<th>' . 'Average weight in hours' . '</th>'
    . '</tr>';

foreach ($data['stats']['storyPointsAverage'] as $storyPoints => $row) {
    // For Google sheets
    //$dataParts = explode(', ', $row);
    echo '<tr>'
        . '<td>' . $storyPoints . '</td>'
        . '<td>' . round($row['avg'], 0) . '</td>'
        . '<td>' . round($row['avg'] / 60, 0) . '</td>'
        . '<td>' . round($row['avg'] / 60 / 60, 2) . '</td>'
        . '</tr>';
}
echo '<table>';
