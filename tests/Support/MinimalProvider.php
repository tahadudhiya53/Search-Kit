<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use craft\base\ElementInterface;
use Tahadudhiya\SearchKit\base\SearchProvider;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;

/**
 * A provider that declares only what it implements, to exercise the base class's capability rules.
 */
class MinimalProvider extends SearchProvider
{
    public static function capabilities(): array
    {
        return [
            ProviderCapability::Search,
            ProviderCapability::Indexing,
        ];
    }

    public function search(SearchQuery $query, SearchIndex $index): SearchResult
    {
        return new SearchResult(['indexHandle' => $index->handle]);
    }

    public function indexElement(SearchIndex $index, ElementInterface $element): void
    {
    }
}
