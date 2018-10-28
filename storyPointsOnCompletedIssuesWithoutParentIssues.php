<?php

require 'vendor/autoload.php';
chdir(__DIR__);


use Dotenv\Dotenv;

$env = new Dotenv(__DIR__);
$env->load();

$storyPointStatistics = new \StoryPoint\Statistics();
$data = $storyPointStatistics->getStatisticsData(['Done', 'QA'], false);

echo 'Story points per issues, UTC+0 timezone, ' . count($data['issues']) . ' issues:'
    . '<hr>';

echo '<table>';

echo '<tr>'
    . '<th>' . 'Toggl latest end date for jira issue' . '</th>'
    . '<th>' . 'Jira issue key' . '</th>'
    . '<th>' . 'Jira issue story points' . '</th>'
    . '<th>' . 'Jira issue title' . '</th>'
    . '<th>' . 'Jira issue status' . '</th>'
    . '<th>' . 'Jira issue subtasks' . '</th>'
    . '<th>' . 'Toggl start date (first)' . '</th>'
    . '<th>' . 'Toggl total duration in seconds' . '</th>'
    . '<th>' . 'Toggl total duration in minutes' . '</th>'
    . '<th>' . 'Toggl total duration in hours' . '</th>'
    . '<th>' . 'Toggl hours per 1 Jira story point' . '</th>'
    . '</tr>';

foreach ($data['issues'] as $row) {
    // For Google sheets
    //$dataParts = explode(', ', $row);
    echo '<tr>'
        . '<td>' . $row['toggl_latest_end_date_formatted_for_jira_issue'] . '</td>'
        . '<td>' . $row['jira_issue_key'] . '</td>'
        . '<td>' . $row['jira_issue_story_points'] . '</td>'
        . '<td>' . $row['jira_issue_title'] . '</td>'
        . '<td>' . $row['jira_issue_status'] . '</td>'
        . '<td>' . count($row['jira_issue_subtasks']) . '</td>'
        . '<td>' . $row['toggl_first_start_date_for_jira_issue'] . '</td>'
        . '<td>' . $row['toggl_total_duration_in_seconds_under_jira_issue'] . '</td>'
        . '<td>' . $row['toggl_total_duration_in_minutes_under_jira_issue'] . '</td>'
        . '<td>' . $row['toggl_total_duration_in_hours_under_jira_issue'] . '</td>'
        . '<td>' . $row['toggl_hours_per_one_jira_story_point'] . '</td>'
        . '</tr>';
}
echo '<table>';
