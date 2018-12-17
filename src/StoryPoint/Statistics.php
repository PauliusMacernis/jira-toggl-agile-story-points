<?php


namespace StoryPoint;

// https://packagist.org/packages/lesstif/php-jira-rest-client
use JiraRestApi\Issue\IssueService;
use JiraRestApi\JiraException;
use MorningTrain\TogglApi\TogglApi;
use OfficialWorkingTime\Lt\BuhalteriamsLt;
use Toggl\TogglTimeEntry;


class Statistics
{
    private const MAX_RESULTS_FROM_JIRA_AT_ONCE = 100; // For some reason, 100 is the max for the library we use or Jira API itself...

    private function getStoryPoints(string $issueSummary): ?float
    {
        return JiraIssue::extractStoryPoints($issueSummary);
    }


    public function getStatisticsData(?array $jiraStatusesToInclude = null, ?bool $includeParentIssues = true, bool $acceptItemsWithoutTogglDuration = false, bool $acceptItemsWithoutJiraStoryPoints = false)
    {
        $results = $this->getDataFromTogglAndJira($jiraStatusesToInclude, $includeParentIssues);
        $grandTotalDataTable = $this->calculateStatistics($results, $acceptItemsWithoutTogglDuration, $acceptItemsWithoutJiraStoryPoints);

        $grandTotalDataTable = $this->injectStoryPointAveragesForEachStoryPointSizeIntoStatistics($grandTotalDataTable);

        return $grandTotalDataTable;

    }

    public function getStatisticsDataOnJiraIssue(string $jiraIssueKey, ?array $jiraStatusesToInclude = null, ?bool $includeParentIssues = true)
    {
        $jiraIssueIdsOccurredInToggl = $this->getToggleTimeEntriesThatHaveJiraIssueKeyMentioned($jiraIssueKey);
        $results = $this->getJiraTasksInfo(array_keys($jiraIssueIdsOccurredInToggl), $jiraStatusesToInclude, $includeParentIssues);
        $results = $this->appendToggleTimeEntriesToJiraTasksInfo($jiraIssueIdsOccurredInToggl, $results);
        $grandTotalDataTable = $this->calculateStatistics($results);

        // Ignoring this because it makes problems when we deal with the single issue that has no Toggl time entries yet.
        // @TODO: Think if we need this line to be uncommented, it seems it is redundant here anyway..
        //$grandTotalDataTable = $this->injectStoryPointAveragesForEachStoryPointSizeIntoStatistics($grandTotalDataTable);

        return $grandTotalDataTable;

    }


    private function getStoryPointAverages(array $storyPointsAverage): array
    {
        // Story point average (per story point value)
        foreach ($storyPointsAverage as &$storyPointsAverageItem) {
            ksort($storyPointsAverageItem['startMin']);
            ksort($storyPointsAverageItem['stopMax']);

            if (count($storyPointsAverageItem['startMin'])) {
                $a = array_filter($storyPointsAverageItem['startMin']);
                if(count($a) === 0) {
                    $storyPointsAverageItem['avg'] = null;
                    continue;
                }
                $storyPointsAverageItem['avg'] = array_sum($a) / count($a);
            }
        }
        unset($storyPointsAverageItem);
        reset($storyPointsAverage);
        ksort($storyPointsAverage);
        return $storyPointsAverage;
    }

    /**
     * @TODO: Get rid of boolean parameter. Boolean parameters is a bad practice though...
     *
     * @param $jiraIssueIdsMentioned
     * @return mixed
     * @throws JiraException
     * @throws \JsonMapper_Exception
     */
    private function getJiraTasksInfo(array $jiraIssueIdsMentioned, ?array $issueStatusesToInclude = null, bool $includeParentIssues = true): array
    {
        $results = [];

        $jiraIssueIdsMentionedInChunks = array_chunk($jiraIssueIdsMentioned, static::MAX_RESULTS_FROM_JIRA_AT_ONCE, true);
        $issueService = new IssueService();

        foreach ($jiraIssueIdsMentionedInChunks as $jiraIssueIdsMentionedInChunk) {

            $jql = $this->getJqlForJiraTasksInfo($issueStatusesToInclude, $jiraIssueIdsMentionedInChunk);
            $ret = $issueService->search($jql, 0, static::MAX_RESULTS_FROM_JIRA_AT_ONCE);

            foreach ($ret->issues as $issue) {

                if ($includeParentIssues === false && !empty($issue->fields->subtasks)) {
                    continue;
                }

                if ($issue->fields->subtasks !== null) {
                    /** @var  $subtask \JiraRestApi\Issue\Issue */
                    foreach ($issue->fields->subtasks as $subtask) {
                        $results[$issue->key]['JIRA']['subtasks'][$subtask->key]['storyPoints'] = $this->getStoryPoints($subtask->fields->summary);
                        $results[$issue->key]['JIRA']['subtasks'][$subtask->key]['summary'] = $subtask->fields->summary;
                        $results[$issue->key]['JIRA']['subtasks'][$subtask->key]['status'] = $subtask->fields->status->name;
                        $results[$issue->key]['JIRA']['subtasks'][$subtask->key]['priority'] = $subtask->fields->priority->name;
                    }
                }

                // Story points
                $results[$issue->key]['JIRA']['storyPoints'] = $this->getStoryPoints($issue->fields->summary);
                $results[$issue->key]['JIRA']['summary'] = $issue->fields->summary;
                $results[$issue->key]['JIRA']['status'] = $issue->fields->status->name;
                $results[$issue->key]['JIRA']['priority'] = $issue->fields->priority->name;
            }

        }
        return $results;
    }

    /**
     * @param $jiraIssueIdsOccurredInToggl
     * @param $results
     * @return mixed
     */
    private function appendToggleTimeEntriesToJiraTasksInfo($jiraIssueIdsOccurredInToggl, $results)
    {
        foreach ($jiraIssueIdsOccurredInToggl as $jiraIssueId => $timeEntriesFromToggl) {
            foreach ($timeEntriesFromToggl['items'] as $togglTimeEntry) {

                $duration = $this->getDuration($togglTimeEntry);

                if (!isset($results[$jiraIssueId])) {
                    $results[$jiraIssueId] = [];
                }
                if (!isset($results[$jiraIssueId]['Toggl'])) {
                    $results[$jiraIssueId]['Toggl'] = [];
                }
                if (!isset($results[$jiraIssueId]['Toggl']['duration'])) {
                    $results[$jiraIssueId]['Toggl']['duration'] = 0;
                }
                $results[$jiraIssueId]['Toggl']['duration'] += $duration;

                $stopDateTime = null;
                if(property_exists($togglTimeEntry, 'stop')) {
                    $stopDateTime = $togglTimeEntry->stop;
                }

                if($stopDateTime === null && property_exists($togglTimeEntry, 'at')) {
                    $stopDateTime = $togglTimeEntry->at;
                }

                if($stopDateTime === null) {
                    throw new \RuntimeException(sprintf('End time not found. Jira issue: %s, Toggl time entry id: %s', $jiraIssueId, $togglTimeEntry->id));
                }

                $results[$jiraIssueId]['Toggl']['entry']['start'][$togglTimeEntry->id] = new \DateTime($togglTimeEntry->start);
                $results[$jiraIssueId]['Toggl']['entry']['stop'][$togglTimeEntry->id] = property_exists($togglTimeEntry, 'stop') ? new \DateTime($togglTimeEntry->stop) : new \DateTime($stopDateTime);
                $results[$jiraIssueId]['Toggl']['entry']['at'][$togglTimeEntry->id] = property_exists($togglTimeEntry, 'at') ? new \DateTime($togglTimeEntry->at) : new \DateTime($stopDateTime);
            }
        }
        return $results;
    }

    private function calculateStatistics(array $results, bool $acceptItemsWithoutTogglDuration = false, bool $acceptItemsWithoutJiraStoryPoints = false): array
    {
        $grandTotalDuration = 0;
        $grandTotalStoryPoints = 0;
        $grandTotalDataTable = [];

        $grandTotalDataTable['stats']['grand_total_story_points'] = 0;
        $grandTotalDataTable['stats']['grand_total_toggl_hours_logged_under_story_points'] = 0;
        $grandTotalDataTable['stats']['grand_total_toggl_minutes_logged_under_story_points'] = 0;

        foreach ($results as $issueId => $totalByToggl) {
            // Skip tasks without duration (just in case)
            if ($acceptItemsWithoutTogglDuration === false && !isset($totalByToggl['Toggl']['duration'])) {
                continue;
            }

            if(!array_key_exists('JIRA', $results[$issueId])) {
                $results[$issueId]['JIRA'] = null;
            }

            // Skip tasks without story points (just in case)
            if ($acceptItemsWithoutJiraStoryPoints === false && !isset($results[$issueId]['JIRA']['storyPoints'])) {
                continue;
            }

//            if(isset($results[$issueId]['JIRA']['subtasks'])) {
//                var_dump($results[$issueId]['JIRA']); die();
//            }


            // @TODO: Ignore tasks with subtasks. For the sake of demo, ignore tasks >= 5 story points
            //if(round($results[$issueId]['JIRA']['storyPoints'], 2) >= 5) {
            //    continue;
            //}

            $grandTotalDuration += $totalByToggl['Toggl']['duration'];

            /** @var \DateTime $startMin */
            $startMin = $results[$issueId]['Toggl']['entry']['startMin'] = min($results[$issueId]['Toggl']['entry']['start']);
            /** @var \DateTime $stopMax */
            $stopMax = $results[$issueId]['Toggl']['entry']['stopMax'] = max($results[$issueId]['Toggl']['entry']['stop']);




            $roundedStoryPoints = round($results[$issueId]['JIRA']['storyPoints'], 2);
            if ($roundedStoryPoints !== 0.00) {
                $grandTotalDataTable['stats']['storyPointsAverage'][(string)$roundedStoryPoints]['startMin'][$startMin->format('U')] = round(round($totalByToggl['Toggl']['duration'], 2) / $roundedStoryPoints, 2);
                $grandTotalDataTable['stats']['storyPointsAverage'][(string)$roundedStoryPoints]['stopMax'][$stopMax->format('U')] = round(round($totalByToggl['Toggl']['duration'], 2) / $roundedStoryPoints, 2);
            } else {
                $grandTotalDataTable['stats']['storyPointsAverage'][(string)$roundedStoryPoints]['startMin'][$startMin->format('U')] = null;
                $grandTotalDataTable['stats']['storyPointsAverage'][(string)$roundedStoryPoints]['stopMax'][$stopMax->format('U')] = null;
            }

            //$stopMaxAsKey = $stopMax->format('U');
            $grandTotalDataTable['issues'][$issueId]['toggl_latest_end_date_formatted_for_jira_issue'] = $stopMax->format('Y-m-d H:i:s');
            $grandTotalDataTable['issues'][$issueId]['toggl_latest_end_date_raw_for_jira_issue'] = $stopMax;
            $grandTotalDataTable['issues'][$issueId]['jira_issue_key'] = $issueId;
            $grandTotalDataTable['issues'][$issueId]['jira_issue_story_points'] = round($results[$issueId]['JIRA']['storyPoints'], 2);
            $grandTotalDataTable['issues'][$issueId]['jira_issue_title'] = $results[$issueId]['JIRA']['summary'];
            $grandTotalDataTable['issues'][$issueId]['jira_issue_status'] = $results[$issueId]['JIRA']['status'];
            $grandTotalDataTable['issues'][$issueId]['jira_issue_priority'] = $results[$issueId]['JIRA']['priority'];
            $grandTotalDataTable['issues'][$issueId]['jira_issue_subtasks'] = isset($results[$issueId]['JIRA']['subtasks']) ? $results[$issueId]['JIRA']['subtasks'] : [];
            $grandTotalDataTable['issues'][$issueId]['toggl_first_start_date_for_jira_issue'] = $startMin->format('Y-m-d H:i:s');
            $grandTotalDataTable['issues'][$issueId]['toggl_total_duration_in_seconds_under_jira_issue'] = round($totalByToggl['Toggl']['duration'], 2);
            $grandTotalDataTable['issues'][$issueId]['toggl_total_duration_in_minutes_under_jira_issue'] = round($totalByToggl['Toggl']['duration'] / 60, 2);
            $grandTotalDataTable['issues'][$issueId]['toggl_total_duration_in_hours_under_jira_issue'] = round($totalByToggl['Toggl']['duration'] / 60 / 60, 2);

            if ($roundedStoryPoints !== 0.00) {
                $grandTotalDataTable['issues'][$issueId]['toggl_hours_per_one_jira_story_point'] = round(round($totalByToggl['Toggl']['duration'] / 60 / 60, 2) / round($results[$issueId]['JIRA']['storyPoints'], 2), 2);
            } else {
                $grandTotalDataTable['issues'][$issueId]['toggl_hours_per_one_jira_story_point'] = null;
            }


            $grandTotalDataTable['stats']['grand_total_story_points'] = round($grandTotalDataTable['stats']['grand_total_story_points'], 2) + round($results[$issueId]['JIRA']['storyPoints'], 2);
            $grandTotalDataTable['stats']['grand_total_toggl_hours_logged_under_story_points'] = round($grandTotalDuration / 60 / 60, 2);
            $grandTotalDataTable['stats']['grand_total_toggl_minutes_logged_under_story_points'] = round($grandTotalDuration / 60, 2);

            $grandTotalDataTable['stats']['one_story_point_equals_to_seconds'] = round($grandTotalDuration / $grandTotalDataTable['stats']['grand_total_story_points'], 2);
            $grandTotalDataTable['stats']['one_story_point_equals_to_minutes'] = round($grandTotalDuration / $grandTotalDataTable['stats']['grand_total_story_points'] / 60, 2);
            $grandTotalDataTable['stats']['one_story_point_equals_to_hours'] = round($grandTotalDuration / $grandTotalDataTable['stats']['grand_total_story_points'] / 60 / 60, 2);

        }
        return $grandTotalDataTable;
    }

    /**
     * @param $grandTotalDataTable
     * @return mixed
     */
    private function injectStoryPointAveragesForEachStoryPointSizeIntoStatistics(array $grandTotalDataTable): array
    {
        // Sort issues so the very latest one appears the first one
        uasort($grandTotalDataTable['issues'], function ($a, $b) {
            if ($a['toggl_latest_end_date_raw_for_jira_issue'] === $b['toggl_latest_end_date_raw_for_jira_issue']) {
                return 0;
            }
            return ($a['toggl_latest_end_date_raw_for_jira_issue'] > $b['toggl_latest_end_date_raw_for_jira_issue']) ? -1 : 1;
        });

        $grandTotalDataTable['stats']['storyPointsAverage'] = $this->getStoryPointAverages($grandTotalDataTable['stats']['storyPointsAverage']);
        return $grandTotalDataTable;
    }

    /**
     * @return array
     */
    public function getToggleTimeEntriesThatHaveJiraIssueKeyMentioned(?string $jiraIssueKeyFromUser = null): array
    {
        $toggl = new TogglApi(getenv('TOGGL_API_TOKEN'));
        $togglTimeEntries = $toggl->getTimeEntriesInRange(getenv('TOGGL_ENTRIES_FROM'), getenv('TOGGL_ENTRIES_TO'));

        // Filter Toggl time entries
        $jiraIssueIdsOccurredInToggl = [];
        foreach ($togglTimeEntries as $togglTimeEntry) {
            $jiraIssueKeyFromToggl = TogglTimeEntry::getJiraIssueId($togglTimeEntry);
            // Case: No jira issue number detected in the time entry
            if (empty($jiraIssueKeyFromToggl)) {
                continue;
            }
            // Case: If the method was asked to return info on particular issue only then all other issues info is ignored.
            if ($jiraIssueKeyFromUser !== null && $jiraIssueKeyFromUser !== $jiraIssueKeyFromToggl) {
                continue;
            }
            $jiraIssueIdsOccurredInToggl[$jiraIssueKeyFromToggl]['items'][$togglTimeEntry->id] = $togglTimeEntry;
        }
        return $jiraIssueIdsOccurredInToggl;
    }


    public function getToggleTimeEntriesByWeekSummedUp(): array
    {
        $toggl = new TogglApi(getenv('TOGGL_API_TOKEN'));
        $togglTimeEntries = $toggl->getTimeEntriesInRange(getenv('TOGGL_ENTRIES_FROM'), getenv('TOGGL_ENTRIES_TO'));

        // Filter Toggl time entries
        $tasksLoggedInToggl = [];
        foreach ($togglTimeEntries as $togglTimeEntry) {

            $from = new \DateTime($togglTimeEntry->start);

            // Finding earliest and latest points in the week of the time entry
            $earliestOfTheWeek = $this->getEarliestOfTheWeek($from);
            $latestOfTheWeek = $this->getLatestOfTheWeek($from);
            $key = sprintf('%s - %s', $earliestOfTheWeek->format('Y-m-d'), $latestOfTheWeek->format('Y-m-d'));

            if(!isset($tasksLoggedInToggl[$key])) {
                $tasksLoggedInToggl[$key]['items'] = [];
                $tasksLoggedInToggl[$key]['total_seconds'] = 0;
                $tasksLoggedInToggl[$key]['total_minutes'] = 0;
                $tasksLoggedInToggl[$key]['earliestOfTheWeek'] = $earliestOfTheWeek;    // Monday (earliest)
                $tasksLoggedInToggl[$key]['latestOfTheWeek'] = $latestOfTheWeek;        // Sunday (latest)
                $tasksLoggedInToggl[$key]['officialWorkingDaysLt'] = $this->getOfficialWorkingDaysLt($earliestOfTheWeek, $latestOfTheWeek);   // Official working days in Lithuania
                $tasksLoggedInToggl[$key]['officialWorkingHoursLt'] = $this->getOfficialWorkingHoursLt($earliestOfTheWeek, $latestOfTheWeek);  // Official working hours in Lithuania
            }

            // Actual items
            $tasksLoggedInToggl[$key]['items'][$togglTimeEntry->id] = $togglTimeEntry;

            // Total time
            // TODO: May seconds reach the level to overflow?
            $tasksLoggedInToggl[$key]['total_seconds'] += $duration = $this->getDuration($togglTimeEntry);
            $tasksLoggedInToggl[$key]['total_minutes'] += round($this->getDuration($togglTimeEntry) / 60, 0);
        }

        return $tasksLoggedInToggl;

    }

    /**
     * @param array|null $issueStatusesToInclude
     * @param $jiraIssueIdsMentionedInChunk
     * @return string
     */
    private function getJqlForJiraTasksInfo(?array $issueStatusesToInclude, $jiraIssueIdsMentionedInChunk): string
    {
        if ($issueStatusesToInclude === null) {
            $jql = 'issuekey in ("' . implode('","', $jiraIssueIdsMentionedInChunk) . '")';
        } else {
            $jql = 'issuekey in ("' . implode('","', $jiraIssueIdsMentionedInChunk) . '") AND status IN ("' . implode('","', $issueStatusesToInclude) . '")';
        }
        return $jql;
    }

    /**
     * Gets the Mondays 00:00:00 date time of the same year
     * or the earliest date of this year if the year has changed this week
     * TODO: A task may be longer than a year? :D
     *
     * @return \DateTime  ISO-8601 week number of year, weeks starting on Monday
     * @throws \Exception
     */
    private function getEarliestOfTheWeek(\DateTime $dateTime): \DateTime
    {
        $dateTimeMonday00 = new \DateTime($dateTime->format('Y-m-d 00:00:00'), $dateTime->getTimezone());

        $year = (int)$dateTime->format('Y');
        if((int)$dateTime->format('n') === 12 && (int)$dateTime->format('W') === 1) {
            // new year, so +1 year
            $year++;
        }

        $dateTimeMonday00->setISODate($year, $dateTime->format('W'), 1);

        return $dateTimeMonday00;
    }

    /**
     * Gets thethe latest DateTime of the week in the year
     * TODO: A task may be longer than a year? :D
     *
     * @param \DateTime $dateTime
     * @return \DateTime
     * @throws \Exception
     */
    private function getLatestOfTheWeek(\DateTime $dateTime): \DateTime
    {
        $dateTimeSundayLatest = new \DateTime($dateTime->format('Y-m-d 23:59:59'), $dateTime->getTimezone());

        $year = $dateTime->format('Y');
        if((int)$dateTime->format('n') === 12 && (int)$dateTime->format('W') === 1) {
            // new year, so +1 year
            $year++;
        }

        // Saturday (not sunday) because sunday (0) would give us sunday of the previous week, not the current one. This is unacceptable while in Europe :)
        $dateTimeSundayLatest->setISODate($year, $dateTime->format('W'), 6);
        $dateTimeSundayLatest->add(new \DateInterval('P1D'));

        return $dateTimeSundayLatest;
    }

    private function getOfficialWorkingDaysLt(\DateTime $earliestOfTheWeek, \DateTime $latestOfTheWeek)
    {
        $buhalteriamsLt = new BuhalteriamsLt();
        return $buhalteriamsLt->getOfficialWorkingDays($earliestOfTheWeek, $latestOfTheWeek);
    }

    private function getOfficialWorkingHoursLt(\DateTime $earliestOfTheWeek, \DateTime $latestOfTheWeek)
    {
        $buhalteriamsLt = new BuhalteriamsLt();
        return $buhalteriamsLt->getOfficialWorkingHours($earliestOfTheWeek, $latestOfTheWeek);
    }

    private function getDuration(\stdClass $togglTimeEntry): int
    {
        if($togglTimeEntry->duration >= 0) {
            return (int)$togglTimeEntry->duration;
        }

        // If the timer is active (ticking) then duration is "minus something" (e.g. "-1542623996")
        return 0;

    }

    public function getDataFromTogglAndJira(?array $jiraStatusesToInclude, ?bool $includeParentIssues)
    {
        $jiraIssueIdsOccurredInToggl = $this->getToggleTimeEntriesThatHaveJiraIssueKeyMentioned();
        $results = $this->getJiraTasksInfo(array_keys($jiraIssueIdsOccurredInToggl), $jiraStatusesToInclude, $includeParentIssues);
        return $this->appendToggleTimeEntriesToJiraTasksInfo($jiraIssueIdsOccurredInToggl, $results);
    }
}