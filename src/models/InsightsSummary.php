<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * The totals for a period: what was searched, what failed, and what came of it.
 */
class InsightsSummary extends Model
{
    public int $totalSearches = 0;

    /** @var int Distinct normalized queries, however many times each was searched for. */
    public int $uniqueQueries = 0;

    public int $zeroResultSearches = 0;
    public int $clicks = 0;

    /** @var int Searches where at least one result was opened. */
    public int $clickedSearches = 0;

    /** @var float Mean milliseconds a search took. */
    public float $averageResponseTime = 0.0;

    /** @var int Searches that took longer than the index calls slow. */
    public int $slowSearches = 0;

    public function getZeroResultRate(): float
    {
        return $this->totalSearches > 0 ? $this->zeroResultSearches / $this->totalSearches : 0.0;
    }

    public function getClickThroughRate(): float
    {
        return $this->totalSearches > 0 ? $this->clickedSearches / $this->totalSearches : 0.0;
    }
}
