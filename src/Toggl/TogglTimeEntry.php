<?php

namespace Toggl;

class TogglTimeEntry
{

    public static function getJiraIssueId(\stdClass $togglTimeEntry): ?string
    {
        $description = trim($togglTimeEntry->description);

        // If description starts with "string dash number" then extract the first match as Jira issue ID
        $result = preg_match('/^[a-zA-Z]*-[\d]*/', $description, $matches, PREG_UNMATCHED_AS_NULL);

        if ($result === false) {
            throw new \RuntimeException(sprintf('preg_match error occurred on the Toggl time entry description %s', $description));
        }

        if ($result === 0 || \count($matches) === 0) {
            return null;
        }

        return reset($matches);

    }
}