<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\Synonyms;

/**
 * Synonyms live in the database, so a unit test sets them in memory instead.
 */
class StubSynonyms extends Synonyms
{
    /** @var array<string,string[]> Expansions per term. */
    public array $expansions = [];

    public function expand(string $term, SearchIndex $index, ?int $siteId = null): array
    {
        return $this->expansions[$term] ?? [];
    }
}
