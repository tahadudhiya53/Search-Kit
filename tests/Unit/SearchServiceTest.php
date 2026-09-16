<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\events\SearchEvent;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Search;
use Tahadudhiya\SearchKit\Tests\Support\StubIndexes;
use Tahadudhiya\SearchKit\Tests\Support\StubProvider;
use Tahadudhiya\SearchKit\Tests\Support\StubProviders;
use Tahadudhiya\SearchKit\Tests\Support\StubSearchableFields;

class SearchServiceTest extends TestCase
{
    private StubProvider $provider;
    private SearchIndex $index;
    private Search $search;

    protected function setUp(): void
    {
        $this->provider = new StubProvider();

        $this->index = new SearchIndex([
            'id' => 1,
            'name' => 'Site Search',
            'handle' => 'siteSearch',
            'provider' => CraftProvider::class,
            'siteId' => 1,
        ]);
        $this->index->setFields([]);

        $providers = new StubProviders();
        $providers->provider = $this->provider;

        $indexes = new StubIndexes();
        $indexes->indexes = [$this->index];

        $this->search = new Search();
        $this->search->setIndexes($indexes);
        $this->search->setProviders($providers);
        $this->search->setSearchableFields(new StubSearchableFields());
    }

    public function testRejectsAnInvalidQueryBeforeTouchingTheProvider(): void
    {
        try {
            $this->search->search(SearchQuery::make('  ', 'siteSearch'));
            self::fail('An invalid query should not reach the provider.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('text', $e->getErrors());
        }

        self::assertNull($this->provider->receivedQuery);
    }

    public function testThrowsWhenTheIndexDoesNotExist(): void
    {
        $this->expectException(IndexNotFoundException::class);
        $this->search->search(SearchQuery::make('boots', 'missing'));
    }

    public function testThrowsWhenTheIndexIsDisabled(): void
    {
        $this->index->enabled = false;

        $this->expectException(IndexDisabledException::class);
        $this->search->search(SearchQuery::make('boots', 'siteSearch'));
    }

    public function testRejectsFiltersTheProviderCannotHonour(): void
    {
        $query = SearchQuery::make('boots', 'siteSearch')
            ->addFilter(SearchFilter::make('sectionId', FilterOperator::Equals, 3));

        $this->expectException(UnsupportedCapabilityException::class);
        $this->search->search($query);
    }

    public function testAcceptsFiltersWhenTheProviderSupportsThem(): void
    {
        $this->provider->supported = [ProviderCapability::Search, ProviderCapability::Filtering];

        $query = SearchQuery::make('boots', 'siteSearch')
            ->addFilter(SearchFilter::make('sectionId', FilterOperator::Equals, 3));

        $this->search->search($query);

        self::assertSame($query, $this->provider->receivedQuery);
    }

    public function testNormalizesTheResultAndReportsExecutionDetail(): void
    {
        $result = $this->search->search(SearchQuery::make('boots', 'siteSearch'));

        self::assertSame('siteSearch', $result->indexHandle);
        self::assertSame(StubProvider::class, $result->provider);
        self::assertGreaterThan(0, $result->executionTime);
    }

    public function testLeavesTheSiteUnresolvedSoTheIndexScopeApplies(): void
    {
        $this->search->search(SearchQuery::make('boots', 'siteSearch'));

        self::assertNull($this->provider->receivedQuery?->siteId);
    }




    public function testNormalizesPaginationOntoTheResult(): void
    {
        $query = SearchQuery::make('boots', 'siteSearch');
        $query->limit = 5;
        $query->offset = 10;

        $result = $this->search->search($query);

        self::assertSame(5, $result->limit);
        self::assertSame(10, $result->offset);
    }

    public function testAnUnexpectedProviderFailureIsReportedWithoutItsInternalDetail(): void
    {
        $this->provider->failWith = new RuntimeException('SQLSTATE[HY000] dsn=mysql://root:hunter2@db');

        try {
            $this->search->search(SearchQuery::make('boots', 'siteSearch'));
            self::fail('A provider failure should surface as a SearchKit exception.');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringNotContainsString('SQLSTATE', $e->getMessage());
            self::assertStringNotContainsString('mysql://', $e->getMessage());
            // The detail is kept for the log, just not for the caller.
            self::assertSame('SQLSTATE[HY000] dsn=mysql://root:hunter2@db', $e->getPrevious()?->getMessage());
        }
    }

    public function testAnalyticsCanObserveEverySearch(): void
    {
        $observed = [];

        $this->search->on(Search::EVENT_AFTER_SEARCH, function(SearchEvent $event) use (&$observed) {
            $observed[] = $event->query->getNormalizedText();
        });

        $this->search->search(SearchQuery::make('winter  boots', 'siteSearch'));

        self::assertSame(['winter boots'], $observed);
    }
}
