<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\base\SearchProvider;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;

/**
 * A provider that identifies elements without loading them, as an external engine would. State is
 * static because SearchKit builds its own instance from the index's stored provider class.
 */
class BareHitProvider extends SearchProvider
{
    /** @var SearchHit[] */
    public static array $hits = [];

    public static function displayName(): string
    {
        return 'Bare hits';
    }

    public static function capabilities(): array
    {
        return [ProviderCapability::Search];
    }

    public static function reset(): void
    {
        self::$hits = [];
    }

    public function search(SearchQuery $query, SearchIndex $index): SearchResult
    {
        return new SearchResult([
            'hits' => self::$hits,
            'total' => count(self::$hits),
        ]);
    }
}
