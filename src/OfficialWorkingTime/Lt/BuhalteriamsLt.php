<?php


namespace OfficialWorkingTime\Lt;


use GuzzleHttp\Client;

class BuhalteriamsLt
{
    public function getOfficialWorkingHours(\DateTime $fromDate, \DateTime $toDate)
    {
        $officialWorkingHoursTdNumber = 11;

        $url = $this->getSourceUrl($fromDate, $toDate);

        $content = $this->getSourceUrlContent($url, $toDate);

        $domDocument = new \DOMDocument();
        $domDocument->loadHTML($content);
        $tds = $domDocument->getElementsByTagName('td');

        if ($tds->count() < 1) {
            throw new \RuntimeException(sprintf('Official working time data (hours) is not found. Service is not accessable? Url: %s', $url));
        }

        $count = 1;
        foreach ($tds as $td) {
            if ($count === $officialWorkingHoursTdNumber) {
                return trim($td->textContent);
            }
            $count++;
        }

        throw new \RuntimeException(sprintf('Official working time data (hours) is not found. Data structure returned by the server has changed? Url: %s', $url));

    }

    public function getOfficialWorkingDays(\DateTime $fromDate, \DateTime $toDate)
    {
        $officialWorkingDaysTdNumber = 8;

        $url = $this->getSourceUrl($fromDate, $toDate);

        $content = $this->getSourceUrlContent($url, $toDate);

        $domDocument = new \DOMDocument();
        $domDocument->loadHTML($content);
        $tds = $domDocument->getElementsByTagName('td');

        if ($tds->count() < 1) {
            throw new \RuntimeException(sprintf('Official working time data (days) is not found. Service is not accessable? Url: %s', $url));
        }

        $count = 1;
        foreach ($tds as $td) {
            if ($count === $officialWorkingDaysTdNumber) {
                return trim($td->textContent);
            }
            $count++;
        }

        throw new \RuntimeException(sprintf('Official working time data (days) is not found. Data structure returned by the server has changed? Url: %s', $url));

    }

    /**
     * @param \DateTime $fromDate
     * @param \DateTime $toDate
     * @return string
     */
    private function getSourceUrl(\DateTime $fromDate, \DateTime $toDate): string
    {
        $urlTemplate = getenv('OFFICIAL_TIME_SERVICE_URL_TEMPLATE_LT');
        $url = strtr($urlTemplate, [
            '{FROM_Y}' => $fromDate->format('Y'),
            '{FROM_m}' => $fromDate->format('m'),
            '{FROM_d}' => $fromDate->format('d'),
            '{TO_Y}' => $toDate->format('Y'),
            '{TO_m}' => $toDate->format('m'),
            '{TO_d}' => $toDate->format('d'),
        ]);
        return $url;
    }

    /**
     * @param $url
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getSourceUrlContent(string $url, \DateTime $toDate): string
    {
        // Check for a cached file (we may get data from there)
        $pathToCacheFile = $this->makePathToCacheFile($url);
        // Check for the cache file
        if (is_file($pathToCacheFile)) {
            return json_decode(file_get_contents($pathToCacheFile));
        }

        $client = new Client([
            'base_uri' => $url
        ]);
        $response = $client->request('GET');

        $content = $response->getBody()->getContents();

        // Save the data returned by the official time service into cache file if this is older weeks. The current week is always incomplete...
        if ($toDate <= new \DateTime('now', $toDate->getTimezone())) {
            file_put_contents($pathToCacheFile, json_encode($content));
        }

        return $content;
    }

    private function makePathToCacheFile(string $url): string
    {
        return getenv('OFFICIAL_TIME_SERVICE_CONTENT_CACHE_DIR_DAY') . DIRECTORY_SEPARATOR . md5($url);
    }
}
