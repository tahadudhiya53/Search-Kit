<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use DateTime;

/**
 * One search that happened. It describes the search and nothing about whoever ran it: no account,
 * no address and no session.
 */
class AnalyticsEvent extends Model
{
    public ?int $id = null;
    public int $indexId = 0;

    /** @var int|null The site searched, or null for a search covering an index's whole scope. */
    public ?int $siteId = null;

    /** @var string What was searched for, as it was typed. */
    public string $query = '';

    /** @var string The same text reduced the way the index reduces it, which queries group by. */
    public string $normalizedQuery = '';

    /** @var string|null What was searched for instead, when the query was corrected before running. */
    public ?string $correctedQuery = null;

    /**
     * @var string|null The language the text was read in, or null when the search covered sites
     * written in more than one and no single language is true of it.
     */
    public ?string $language = null;

    public int $resultCount = 0;

    /** @var float Milliseconds the search took. */
    public float $executionTime = 0.0;

    /** @var int How many of this search's results were opened. */
    public int $clickCount = 0;

    public ?DateTime $dateCreated = null;

    /** @var string|null The token a result carries back when it is clicked. */
    public ?string $uid = null;

    public function foundNothing(): bool
    {
        return $this->resultCount === 0;
    }

    public function wasClicked(): bool
    {
        return $this->clickCount > 0;
    }

    public function wasCorrected(): bool
    {
        return $this->correctedQuery !== null;
    }
}
