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
    public string $provider = '';

    /** @var float Milliseconds spent executing the search. */
    public float $executionTime = 0.0;

    /** @var array<string,mixed> Provider-reported execution detail. */
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
     * @return int[]
     */
    public function getElementIds(): array
    {
        return array_map(static fn(SearchHit $hit) => $hit->elementId, $this->hits);
    }
}
