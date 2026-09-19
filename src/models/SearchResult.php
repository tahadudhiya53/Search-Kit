<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * A normalized search response, plus the execution detail ranking explanations are built from.
 */
class SearchResult extends Model
{
    /** @var SearchHit[] */
    public array $hits = [];

    public int $total = 0;
    public int $limit = 0;
    public int $offset = 0;
    public string $indexHandle = '';

    /** @var string Diagnostic: the provider class that served this search, not part of the contract. */
    public string $provider = '';

    /** @var float Milliseconds spent executing the search. */
    public float $executionTime = 0.0;

    /** @var array<string,mixed> Diagnostic: whatever the provider reported about the execution. */
    public array $metadata = [];

    /** @var string|null What was searched for instead, when the query was corrected before running. */
    public ?string $correctedText = null;

    /** @var string[] Queries worth trying instead, offered when this one found nothing. */
    public array $suggestions = [];

    /** @var ParsedQuery|null The terms this result was produced from, for explaining a match. */
    public ?ParsedQuery $parsedQuery = null;

    /** @var string|null Where a matching search rule says this query should be sent instead. */
    public ?string $redirect = null;

    /** @var RuleEvaluation[] Every search rule considered, matched or not, and what it did. */
    public array $rules = [];

    /**
     * @var string|null The token this search was recorded under, when it was. A template posts it
     * back to associate a result someone opened with the search that found it. It identifies the
     * search, never the person who made it.
     */
    public ?string $trackingToken = null;

    public function wasCorrected(): bool
    {
        return $this->correctedText !== null;
    }

    public function isTracked(): bool
    {
        return $this->trackingToken !== null;
    }

    public function hasRedirect(): bool
    {
        return $this->redirect !== null;
    }

    /**
     * The rules that governed this search, for a template that shows why a result is where it is.
     *
     * @return RuleEvaluation[]
     */
    public function getMatchedRules(): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn(RuleEvaluation $evaluation) => $evaluation->matched,
        ));
    }

    public function hasSuggestions(): bool
    {
        return $this->suggestions !== [];
    }

    public function getCount(): int
    {
        return count($this->hits);
    }

    public function getPage(): int
    {
        return $this->limit > 0 ? (int)floor($this->offset / $this->limit) + 1 : 1;
    }

    public function getPageCount(): int
    {
        return $this->limit > 0 ? (int)ceil($this->total / $this->limit) : ($this->total > 0 ? 1 : 0);
    }

    /**
     * Whether there are results after this window, which an offset of its own may sit inside.
     */
    public function getHasNextPage(): bool
    {
        return $this->limit > 0 && $this->offset + $this->limit < $this->total;
    }

    public function getHasPreviousPage(): bool
    {
        return $this->offset > 0;
    }

    public function getNextPage(): ?int
    {
        return $this->getHasNextPage() ? $this->getPage() + 1 : null;
    }

    public function getPreviousPage(): ?int
    {
        return $this->getHasPreviousPage() ? max(1, $this->getPage() - 1) : null;
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    /**
     * The elements the hits matched, in the order they were ranked.
     *
     * @return \craft\base\ElementInterface[]
     */
    public function getElements(): array
    {
        return array_values(array_filter(array_map(static fn(SearchHit $hit) => $hit->element, $this->hits)));
    }

    /**
     * @return int[]
     */
    public function getElementIds(): array
    {
        return array_map(static fn(SearchHit $hit) => $hit->elementId, $this->hits);
    }
}
