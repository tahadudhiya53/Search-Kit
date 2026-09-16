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
