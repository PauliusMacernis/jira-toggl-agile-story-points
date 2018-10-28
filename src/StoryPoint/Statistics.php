<?php


namespace StoryPoint;

// https://packagist.org/packages/lesstif/php-jira-rest-client
use JiraRestApi\Issue\IssueService;
use JiraRestApi\JiraException;
use MorningTrain\TogglApi\TogglApi;
use Toggl\TogglTimeEntry;


class Statistics
{
    private const MAX_RESULTS_FROM_JIRA_AT_ONCE = 100; // For some reason, 100 is the max for the library we use or Jira API itself...

    private function getStoryPoints(string $issueSummary): ?float
    {
        return JiraIssue::extractStoryPoints($issueSummary);
    }


    public function getStatisticsData(?array $jiraStatusesToInclude = null, ?bool $includeParentIssues = true)
    {
        $jiraIssueIdsOccurredInToggl = $this->getToggleTimeEntriesThatHaveJiraIssueKeyMentioned();
        $results = $this->getJiraTasksInfo(array_keys($jiraIssueIdsOccurredInToggl), $jiraStatusesToInclude, $includeParentIssues);
        $results = $this->appendToggleTimeEntriesToJiraTasksInfo($jiraIssueIdsOccurredInToggl, $results);
        $grandTotalDataTable = $this->calculateStatistics($results);

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
                if (!isset($results[$jiraIssueId])) {
                    $results[$jiraIssueId] = [];
                }
                if (!isset($results[$jiraIssueId]['Toggl'])) {
                    $results[$jiraIssueId]['Toggl'] = [];
                }
                if (!isset($results[$jiraIssueId]['Toggl']['duration'])) {
                    $results[$jiraIssueId]['Toggl']['duration'] = 0;
                }
                $results[$jiraIssueId]['Toggl']['duration'] += $togglTimeEntry->duration;

                $results[$jiraIssueId]['Toggl']['entry']['start'][$togglTimeEntry->id] = new \DateTime($togglTimeEntry->start);
                $results[$jiraIssueId]['Toggl']['entry']['stop'][$togglTimeEntry->id] = new \DateTime($togglTimeEntry->stop);
                $results[$jiraIssueId]['Toggl']['entry']['at'][$togglTimeEntry->id] = new \DateTime($togglTimeEntry->at);
            }
        }
        return $results;
    }

    /**
     * @param $results
     * @return array
     */
    private function calculateStatistics($results): array
    {
        $grandTotalDuration = 0;
        $grandTotalStoryPoints = 0;
        $grandTotalDataTable = [];

        $grandTotalDataTable['stats']['grand_total_story_points'] = 0;
        $grandTotalDataTable['stats']['grand_total_toggl_hours_logged_under_story_points'] = 0;
        $grandTotalDataTable['stats']['grand_total_toggl_minutes_logged_under_story_points'] = 0;

        foreach ($results as $issueId => $totalByToggl) {
            // Skip tasks without duration (just in case)
            if (!isset($totalByToggl['Toggl']['duration'])) {
                continue;
            }
            // Skip tasks without story points (just in case)
            if (!isset($results[$issueId]['JIRA']['storyPoints'])) {
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
            if ($roundedStoryPoints === 0.00) {
                continue;
            }
            $grandTotalDataTable['stats']['storyPointsAverage'][(string)$roundedStoryPoints]['startMin'][$startMin->format('U')] = round(round($totalByToggl['Toggl']['duration'], 2) / $roundedStoryPoints, 2);
            $grandTotalDataTable['stats']['storyPointsAverage'][(string)$roundedStoryPoints]['stopMax'][$stopMax->format('U')] = round(round($totalByToggl['Toggl']['duration'], 2) / $roundedStoryPoints, 2);


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
            $grandTotalDataTable['issues'][$issueId]['toggl_hours_per_one_jira_story_point'] = round(round($totalByToggl['Toggl']['duration'] / 60 / 60, 2) / round($results[$issueId]['JIRA']['storyPoints'], 2), 2);

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
    private function getToggleTimeEntriesThatHaveJiraIssueKeyMentioned(?string $jiraIssueKeyFromUser = null): array
    {
        $toggl = new TogglApi(getenv('TOGGL_API_TOKEN'));
        $togglTimeEntries = $toggl->getTimeEntriesInRange(getenv('TOGGL_ENTRIES_FROM'), getenv('TOGGL_ENTRIES_TO'));

        // Filter Toggl time entries
        $jiraIssueIdsOccurredInToggl = [];
        foreach ($togglTimeEntries as $togglTimeEntry) {
            $jiraIssueKeyFromToggl = TogglTimeEntry::getJiraIssueId($togglTimeEntry);
            if (empty($jiraIssueKeyFromToggl)) {
                continue;
            }
            if ($jiraIssueKeyFromUser !== null && $jiraIssueKeyFromUser !== $jiraIssueKeyFromToggl) {
                continue;
            }
            $jiraIssueIdsOccurredInToggl[TogglTimeEntry::getJiraIssueId($togglTimeEntry)]['items'][$togglTimeEntry->id] = $togglTimeEntry;
        }
        return $jiraIssueIdsOccurredInToggl;
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
}