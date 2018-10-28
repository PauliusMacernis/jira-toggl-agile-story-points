<?php

require 'vendor/autoload.php';
chdir(__DIR__);


use Dotenv\Dotenv;

$env = new Dotenv(__DIR__);
$env->load();

$storyPointStatistics = new \StoryPoint\Statistics();
$data = $storyPointStatistics->getStatisticsData(['Done', 'QA'], false);

echo '1 story point = '
    . round($data['stats']['grand_total_toggl_minutes_logged_under_story_points'] / $data['stats']['grand_total_story_points'], 2)
    . ' minutes'
    . ' ~ '
    . round($data['stats']['grand_total_toggl_hours_logged_under_story_points'] / $data['stats']['grand_total_story_points'], 2)
    . ' hours'
    . '<hr>';

