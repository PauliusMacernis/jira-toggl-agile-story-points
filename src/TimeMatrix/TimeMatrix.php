<?php


namespace TimeMatrix;


abstract class TimeMatrix
{
    protected $matrix = [];

    protected const INDEX_TIME_START = 'start';
    protected const INDEX_TIME_STOP = 'stop';


    public function getMatrix(): array
    {
        return $this->matrix;
    }

    private function matrixSorterDesc($a, $b): int
    {
        if ($a === $b) {
            return 0;
        }
        return ($a > $b) ? -1 : 1;
    }

    private function matrixSorterAsc($a, $b): int
    {
        if ($a === $b) {
            return 0;
        }
        return ($a < $b) ? -1 : 1;
    }

    public function sortMatrixByValueDesc(?array &$customMatrix = null): void
    {
        if ($customMatrix !== null) {
            uasort($customMatrix, [$this, 'matrixSorterDesc']);
            reset($customMatrix);
            return;
        }

        uasort($this->matrix, [$this, 'matrixSorterDesc']);
        reset($this->matrix);
    }

    public function sortMatrixByKeyAsc(?array &$customMatrix = null): void
    {
        if ($customMatrix !== null) {
            uksort($customMatrix, [$this, 'matrixSorterAsc']);
            reset($customMatrix);
            return;
        }

        uksort($this->matrix, [$this, 'matrixSorterAsc']);
        reset($this->matrix);
    }

    public function getFattySliceOfSortedMatrix(int $minutesToSlice): array
    {
        $this->sortMatrixByValueDesc();

        $fatSliceOfMatrix = \array_slice($this->getMatrix(), 0, $minutesToSlice, true);
        $this->sortMatrixByKeyAsc($fatSliceOfMatrix);
        return $fatSliceOfMatrix;
    }

    protected function setMatrix(int $start, int $end, ?array $matrix = null): void
    {
        if ($matrix !== null) {
            $this->matrix = $matrix;
            return;
        }

        for ($i = $start; $i <= $end; $i++) {
            $this->matrix[$i] = 0;
        }
    }

}