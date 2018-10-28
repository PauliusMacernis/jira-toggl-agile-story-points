<?php


namespace StoryPoint;


use JiraRestApi\Issue\IssueService;


class JiraIssue
{
    private $issue;

    public function __construct(string $issueKey)
    {
        $issueService = new IssueService();
        $this->setIssue($issueService->get($issueKey));
    }

    private function getIssue()
    {
        return $this->issue;
    }

    private function setIssue($issue): void
    {
        $this->issue = $issue;
    }

    public function getStoryPoints(): ?float
    {
        return static::extractStoryPoints($this->issue->fields->summary);
    }

    public static function extractStoryPoints(string $issueSummary): ?float
    {
        $storyPointsPattern = '#^\[(\d+\.*\d*)#';
        preg_match($storyPointsPattern, trim($issueSummary), $match);
        return (key_exists(1, $match) ? (float)$match[1] : null);
    }


}