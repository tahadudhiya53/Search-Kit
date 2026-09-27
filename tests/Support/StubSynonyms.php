<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\Synonyms;

/**
 * Synonyms live in the database, so a unit test sets them in memory instead. Only the groups and
 * the sites are stood in for: how a scope is settled across them is the real thing.
 */
class StubSynonyms extends Synonyms
{
    /** @var array<string,string[]> Expansions per term, for a search of one site. */
    public array $expansions = [];

    /** @var array<int,array<string,string[]>> Expansions per term, per site. */
    public array $expansionsBySite = [];

    /** @var int[] The sites a search is treated as covering. */
    public array $sites = [1];

    public function expand(string $term, SearchIndex $index, ?int $siteId = null): array
    {
        if ($siteId !== null && isset($this->expansionsBySite[$siteId])) {
            return $this->expansionsBySite[$siteId][$term] ?? [];
        }

        return $this->expansions[$term] ?? [];
    }

    protected function sitesInScope(SearchIndex $index, ?array $siteIds): array
    {
        return $siteIds ?? $this->sites;
    }
}
