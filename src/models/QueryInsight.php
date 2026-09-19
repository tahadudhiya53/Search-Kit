<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * What one query looks like over a period: how often it was searched for, how often it found
 * nothing, and how often anything came of it.
 */
class QueryInsight extends Model
{
    /** @var string The normalized form every one of these searches shares. */
    public string $query = '';

    public int $searches = 0;

    /** @var int How many of those searches returned nothing at all. */
    public int $zeroResults = 0;

    /** @var int Results opened across every one of those searches. */
    public int $clicks = 0;

    /** @var int Searches where at least one result was opened, which is what a rate is counted on. */
    public int $clickedSearches = 0;

    /** @var float Mean milliseconds these searches took. */
    public float $averageTime = 0.0;

    /**
     * How often a search for this ended in a result being opened.
     */
    public function getClickThroughRate(): float
    {
        return $this->searches > 0 ? $this->clickedSearches / $this->searches : 0.0;
    }

    public function getZeroResultRate(): float
    {
        return $this->searches > 0 ? $this->zeroResults / $this->searches : 0.0;
    }

    /**
     * Whether nothing was ever found for this, as opposed to found and ignored.
     */
    public function findsNothing(): bool
    {
        return $this->searches > 0 && $this->zeroResults === $this->searches;
    }
}
