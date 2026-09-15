<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use Tahadudhiya\SearchKit\base\SearchProvider;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Throwable;

/**
 * A provider whose capabilities and response are set per test.
 */
class StubProvider extends SearchProvider
{
    /** @var ProviderCapability[] */
    public array $supported = [ProviderCapability::Search];

    public ?SearchQuery $receivedQuery = null;
    public ?SearchIndex $receivedIndex = null;
    public ?Throwable $failWith = null;
    public SearchResult $result;

    public function init(): void
    {
        parent::init();
        $this->result = new SearchResult(['total' => 1]);
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->supported, true);
    }

    public function search(SearchQuery $query, SearchIndex $index): SearchResult
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->receivedQuery = $query;
        $this->receivedIndex = $index;

        return $this->result;
    }
}
