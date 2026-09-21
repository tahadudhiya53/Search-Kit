<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * Two queries that people open the same results from. That is evidence they mean the same thing to
 * whoever searched — not proof, which is why this is only ever offered for somebody to decide on.
 *
 * A candidate carries the site scope it was observed in. Both queries were searched under that same
 * scope, read in that scope's language, so a pair never puts together what people did in one site
 * with what they did in another.
 */
class SynonymCandidate extends Model
{
    public string $first = '';
    public string $second = '';

    /**
     * @var int|null The site both queries were searched in, or null where both were searched across
     * the whole of the index's scope. A group written from this belongs to the same scope.
     */
    public ?int $siteId = null;

    public int $firstSearches = 0;
    public int $secondSearches = 0;

    /** @var int Results opened from both queries, counted as element and site. */
    public int $sharedResults = 0;

    /** @var int Distinct results opened from the first query. */
    public int $firstResults = 0;

    public int $secondResults = 0;

    /**
     * How much of the narrower query's opened results the two have in common. Measured against the
     * narrower one, so a query with one good result is not penalised for having only one.
     */
    public function getOverlap(): float
    {
        $narrower = min($this->firstResults, $this->secondResults);

        return $narrower > 0 ? $this->sharedResults / $narrower : 0.0;
    }

    public function getSearches(): int
    {
        return $this->firstSearches + $this->secondSearches;
    }

    /**
     * @return string[]
     */
    public function getTerms(): array
    {
        return [$this->first, $this->second];
    }

    /**
     * Whether the pair was observed in one site rather than across the index's whole scope.
     */
    public function isSiteSpecific(): bool
    {
        return $this->siteId !== null;
    }

    /**
     * Why these two look like the same thing, in the numbers it was read from.
     */
    public function getEvidence(): string
    {
        return sprintf(
            '“%s” (%d searches) and “%s” (%d searches) share %d of the results people opened, %s of the narrower one.',
            $this->first,
            $this->firstSearches,
            $this->second,
            $this->secondSearches,
            $this->sharedResults,
            round($this->getOverlap() * 100) . '%',
        );
    }
}
