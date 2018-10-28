<?php

require 'vendor/autoload.php';
chdir(__DIR__);


use Dotenv\Dotenv;

$env = new Dotenv(__DIR__);
$env->load();

$storyPointStatistics = new \StoryPoint\Statistics();
$data = $storyPointStatistics->getStatisticsData(['Done', 'QA'], false);

$issueKey = trim($_REQUEST['issueKey']);

if (empty($issueKey)) {
    echo sprintf('Jira issue key is not provided. You need to give an issue key (hint: it looks like "AP-108") in order to continue.');
}

$issue = new \StoryPoint\JiraIssue($issueKey);
$storyPoints = $issue->getStoryPoints();
if (empty($storyPoints)) { // null, 0, "", etc.
    echo sprintf('The issue "%s" is not estimated. Write the scope of the task in story points in front of the issue title in Jira.', $issueKey);
}

$multiplierOfStoryPointsForMinutes = $data['stats']['one_story_point_equals_to_minutes'];
if (empty($multiplierOfStoryPointsForMinutes)) {
    echo sprintf('There is no enough tasks completed to be able to analyze a time in Toggl (there must be time entries referring to Jira issue keys, e.g. "AP-108 - PIM import", - the key "AP-108" is important, the rest - not.).');
}

$statistics = new \StoryPoint\Statistics();
$statisticsOnJiraIssue = $statistics->getStatisticsDataOnJiraIssue($issueKey);
$timeSpentOnIssueInMinutes = $statisticsOnJiraIssue['stats']['grand_total_toggl_minutes_logged_under_story_points'];

$timeLeftOnIssueInMinutes = $storyPoints * $multiplierOfStoryPointsForMinutes - $timeSpentOnIssueInMinutes;

$timeLeftInMinutes = (int)round($storyPoints * $multiplierOfStoryPointsForMinutes - $timeSpentOnIssueInMinutes, 2);
$timeLeftInHours = round(round($storyPoints * $multiplierOfStoryPointsForMinutes - $timeSpentOnIssueInMinutes, 0) / 60, 2);

if ($timeLeftInMinutes <= 0) {
    echo sprintf('<br>Delaying: %s minutes or ~%s hours.', (-1 * $timeLeftInMinutes), (-1 * $timeLeftInHours));
} else {
    echo sprintf('<br>Time left: %s minutes or ~%s hours.', $timeLeftInMinutes, round($timeLeftInHours, 2));
}
echo sprintf('<br>Time spent: %s minutes or ~%s hours.', round($timeSpentOnIssueInMinutes, 0), round($timeSpentOnIssueInMinutes / 60, 0));
echo sprintf('<br>Progress: %s', round($timeSpentOnIssueInMinutes * 100 / ($timeLeftInMinutes + $timeSpentOnIssueInMinutes), 2)) . '%';


$oneWeekMinutes = (int)getenv('WORKING_WEEK_LENGTH_IN_MINUTES');

$weeksLeftFull = 0;
$minutesInLastWeek = 0;
if ($timeLeftInMinutes >= 0) {
    $weeksLeftFull = (int)floor($timeLeftInMinutes / $oneWeekMinutes);
    $minutesInLastWeek = (int)ceil($timeLeftInMinutes - ($weeksLeftFull * $oneWeekMinutes)); // if 1.1 minutes left, it means it is 2 minutes required
}

//var_dump('<br>Full weeks left: ', $weeksLeftFull);
//var_dump('<br>Minutes in last week: ', $minutesInLastWeek);

if ($timeLeftInMinutes > $oneWeekMinutes) {
    echo '<br><br>';
    echo sprintf('Resolving the issue will take %s full workweek(s) (1 workweek = %s minutes or %s hours) and a bit of time (%s minutes or ~%s hours) in the next week.', $weeksLeftFull, $oneWeekMinutes, round($oneWeekMinutes / 60, 0), $minutesInLastWeek, round($minutesInLastWeek / 60, 0));
}

echo '<br><br>';
echo 'National and work holidays are not taken into account.';
echo '<br>';
echo 'Daylight saving time is (not) taken into account.';

$now = new DateTime('now', new DateTimeZone('UTC'));
if ($weeksLeftFull > 0) {
    $now->add(new DateInterval('P' . (7 * $weeksLeftFull) . 'D')); // // PXD - a Period of X Days
}

$weekMatrix = new \TimeMatrix\Week();
$fattySliceOfSortedMatrix = $weekMatrix->getMatrixOfActualWorkweekMinutesSortedByMinutesAsc();
$nextMinuteInMatrix = \TimeMatrix\Week::getNextMinuteFromKeysOfMatrix($fattySliceOfSortedMatrix);

$minutesInMatrix = array_keys($fattySliceOfSortedMatrix);
$firstMinuteInMinutesMatrix = reset($minutesInMatrix);
$indexOfNextMinuteInMinutesInMatrix = array_search($nextMinuteInMatrix, $minutesInMatrix, true);
if ($indexOfNextMinuteInMinutesInMatrix === false) {
    throw new RuntimeException('Something is bad in the flow of the code. File:' . __FILE__ . ': ' . __LINE__);
}

//var_dump('<br>Index: ', $indexOfNextMinuteInMinutesInMatrix);
//array_chunk($minutesInMatrix, )

//var_dump($minutesInMatrix); die(); // { [0]=> int(371) [1]=> int(372) [2]=> int(391) [3]=> int(392) [4]=> int(393) [5]=> int(394) [6]=> int(395)
//$indexOfNextMinuteInMinutesInMatrix = 0;
$minutesLeftInTheWorkWeekStartingWithNextMinuteInMatrixIncl = array_slice($minutesInMatrix, $indexOfNextMinuteInMinutesInMatrix);
$minutesLeftInTheWorkWeekEndingWithNextMinuteInMatrixIncl = array_slice($minutesInMatrix, 0, $indexOfNextMinuteInMinutesInMatrix + 1); // plus 1 because we want the actual value to be included as well

//var_dump('<br>Minutes End, Start:', count($minutesLeftInTheWorkWeekStartingWithNextMinuteInMatrixIncl), count($minutesLeftInTheWorkWeekEndingWithNextMinuteInMatrixIncl));

if ($minutesInLastWeek >= $minutesLeftInTheWorkWeekEndingWithNextMinuteInMatrixIncl) {
    // We need to jump one more workweek in front because there is no enough minutes in the last week left that would cover a reminder of partial workweek
    $now->add(new DateInterval('P7D'));

    // Jump to Monday 00:00:00 of the extra week so we can calculate minutes found in matrix from this point later on
    $nowWorkdayCode = $now->format('w');
    if ($nowWorkdayCode === 0) { // Fixing sunday from 0 to 7, 1 - Monday, ..., 7 - Sunday
        $nowWorkdayCode = 7;
    }
    $now = new DateTime($now->format('Y-m-dT00:00:00'), new DateTimeZone('UTC'));
    if ($nowWorkdayCode !== 1) {
        $daysToMonday = $nowWorkdayCode - 1;
        $now->modify("-{$daysToMonday} days"); // aka. "DateTime value for $minutes on this range case"
    }
    // Add the amount of values left
    $minutesLeft = $minutesInLastWeek - $minutesLeftInTheWorkWeekEndingWithNextMinuteInMatrixIncl;
    $finalMinute = $minutesInMatrix[$minutesLeft];
    //var_dump('x123');
} else {
    // We do need to jump to Monday 00:00:00 of the same week because we will not be able to count the days value got from the workweek matrix
    $nowWorkdayCode = $now->format('w');
    if ($nowWorkdayCode === 0) { // Fixing sunday from 0 to 7, 1 - Monday, ..., 7 - Sunday
        $nowWorkdayCode = 7;
    }
    $now = new DateTime($now->format('Y-m-dT00:00:00'), new DateTimeZone('UTC'));
    if ($nowWorkdayCode !== 1) {
        $daysToMonday = $nowWorkdayCode - 1;
        $now->modify("-{$daysToMonday} days"); // aka. "DateTime value for $minutes on this range case"
    }

    // Will be on the same week, no need to go one week up in order to find a minute of a partial workweek
    $finalMinute = $minutesInMatrix[$minutesInLastWeek];
    //var_dump('x456');
}

//var_dump('<br>Now:', $now);
//var_dump('<br>Final minute:', $finalMinute);
//var_dump($fattySliceOfSortedMatrix); die();

$now->modify("+{$finalMinute} minutes"); // aka. "DateTime value for $minutes on this range case"

//var_dump('<br>Now:', $now);

echo '<hr>';
echo sprintf('The issue "%s" will be resolved on %s. UTC0 timezone.', $issueKey, $now->format('Y-m-d H:i:s, l'));

die();

