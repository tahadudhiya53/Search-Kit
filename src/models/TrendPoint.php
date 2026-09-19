<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * One day of search activity, which is what a trend is a series of. A point covers several days
 * once a long period has been condensed, which is what the far end is for.
 */
class TrendPoint extends Model
{
    /** @var string The first day this point covers, as `Y-m-d`. */
    public string $date = '';

    /** @var string The last day this point covers, which is the same day until days are grouped. */
    public string $dateEnd = '';

    public int $searches = 0;
    public int $zeroResults = 0;
    public int $clicks = 0;

    /** @var float Mean milliseconds the searches in this point took. */
    public float $averageTime = 0.0;

    /**
     * Whether this point stands for more than the one day it is named after.
     */
    public function coversRange(): bool
    {
        return $this->dateEnd !== '' && $this->dateEnd !== $this->date;
    }
}
