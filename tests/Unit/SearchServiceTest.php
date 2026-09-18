<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\events\SearchEvent;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Normalization;
use Tahadudhiya\SearchKit\services\QueryPipeline;
use Tahadudhiya\SearchKit\services\RuleEngine;
use Tahadudhiya\SearchKit\services\Search;
use Tahadudhiya\SearchKit\services\StopWords;
use Tahadudhiya\SearchKit\Tests\Support\StubIndexes;
use Tahadudhiya\SearchKit\Tests\Support\StubProvider;
use Tahadudhiya\SearchKit\Tests\Support\StubProviders;
use Tahadudhiya\SearchKit\Tests\Support\StubRules;
use Tahadudhiya\SearchKit\Tests\Support\StubSearchableFields;
use Tahadudhiya\SearchKit\Tests\Support\StubSuggestions;

class SearchServiceTest extends TestCase
{
    private StubProvider $provider;
    private SearchIndex $index;
    private Search $search;
    private StubSuggestions $suggestions;
    private StubProviders $providers;
    private StubIndexes $indexes;

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
        $this->index->setFields([new SearchableField(['elementType' => 'craft\\elements\\Entry', 'handle' => 'title', 'weight' => 5])]);

        $this->providers = new StubProviders();
        $this->providers->provider = $this->provider;

        $this->indexes = new StubIndexes();
        $this->indexes->indexes = [$this->index];

        $this->suggestions = new StubSuggestions();

        $this->search = new Search();
        $this->search->setIndexes($this->indexes);
        $this->search->setProviders($this->providers);
        $this->search->setSearchableFields(new StubSearchableFields());
        $this->search->setQueryPipeline(self::pipeline());
        $this->search->setSuggestions($this->suggestions);
        $this->search->setRuleEngine(self::ruleEngine());
    }

    /**
     * A pipeline with nothing behind it but text handling, so a unit test needs no Craft app.
     */
    public static function pipeline(): QueryPipeline
    {
        $normalization = new Normalization();
        $normalization->language = 'en-US';

        $stopWords = new StopWords();
        $stopWords->setNormalization($normalization);

        $pipeline = new QueryPipeline();
        $pipeline->setNormalization($normalization);
        $pipeline->setStopWords($stopWords);

        return $pipeline;
    }

    /**
     * An engine with no rules behind it, so a unit test needs neither a database nor a Craft app.
     */
    public static function ruleEngine(): RuleEngine
    {
        $normalization = new Normalization();
        $normalization->language = 'en-US';

        $rules = new StubRules();
        $rules->setNormalization($normalization);

        $engine = new RuleEngine();
        $engine->setRules($rules);
        $engine->setNormalization($normalization);

        return $engine;
    }

    /**
     * @dataProvider siteCounts
     */
    public function testEveryBoostedResultIsAskedForEvenBeyondWhatOneSearchMayCarry(int $sites): void
    {
        // A search whose site count is set rather than read from Craft, so how many results fit in
        // one search can be exercised without a multi-site installation behind it.
        $search = new class($sites) extends Search {
            public function __construct(private readonly int $sites)
            {
                parent::__construct();
            }

            protected function scopeSiteCount(): int
            {
                return $this->sites;
            }
        };

        $search->setIndexes($this->indexes);
        $search->setProviders($this->providers);
        $search->setSearchableFields(new StubSearchableFields());
        $search->setQueryPipeline(self::pipeline());
        $search->setSuggestions($this->suggestions);
        $this->search = $search;

        // An index covering every site is what makes one result answer more than once.
        $this->index->siteId = $sites > 1 ? null : 1;

        $this->provider->supported = [
            ProviderCapability::Search,
            ProviderCapability::Filtering,
            ProviderCapability::ResultExclusion,
        ];

        // More (result, site) pairs than a single search may return, so they cannot be asked at once.
        $targets = range(1, (int)ceil((SearchQuery::MAX_LIMIT + 500) / $sites));
        $rules = new StubRules();
        $rules->setNormalization(self::normalization());
        $rules->rules = [$this->boostRule($targets)];

        $engine = new RuleEngine();
        $engine->setRules($rules);
        $engine->setNormalization(self::normalization());
        $this->search->setRuleEngine($engine);

        $this->search->search(SearchQuery::create('siteSearch', 'shoes'));

        $asked = [];
        $probes = 0;

        foreach ($this->provider->receivedQueries as $received) {
            foreach ($received->getFilters() as $filter) {
                if ($filter->field !== 'id') {
                    continue;
                }

                $probes++;
                $ids = array_map('intval', (array)$filter->value);

                self::assertLessThanOrEqual(
                    SearchQuery::MAX_LIMIT,
                    $received->limit,
                    'no search may be asked for more than it is allowed to return',
                );

                // The whole point: a search must be able to return every result it was asked about.
                self::assertLessThanOrEqual(
                    $received->limit,
                    count($ids) * $sites,
                    'a search asked about more rows than it could return would silently drop some',
                );

                $asked = [...$asked, ...$ids];
            }
        }

        self::assertGreaterThan(1, $probes, 'this many results cannot be asked for in one search');

        // Every boosted result was asked for, exactly once, across however many searches it took.
        self::assertSame($targets, $asked);
    }

    /**
     * @return array<string,array{int}>
     */
    public static function siteCounts(): array
    {
        return ['one site' => [1], 'three sites' => [3]];
    }

    /**
     * @param int[] $targets
     */
    private function boostRule(array $targets): SearchRule
    {
        $rule = new SearchRule([
            'id' => 1,
            'indexId' => 1,
            'name' => 'Boost everything',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => 'shoes',
        ]);

        $rule->setActions(array_map(static fn(int $id) => new RuleAction([
            'type' => RuleActionType::Boost,
            'elementId' => $id,
            'elementType' => 'craft\\elements\\Entry',
            'amount' => 10.0,
        ]), $targets));

        return $rule;
    }

    private static function normalization(): Normalization
    {
        $normalization = new Normalization();
        $normalization->language = 'en-US';

        return $normalization;
    }

    public function testRejectsAnInvalidQueryBeforeTouchingTheProvider(): void
    {
        try {
            $this->search->search(SearchQuery::create('siteSearch', '  '));
            self::fail('An invalid query should not reach the provider.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('text', $e->getErrors());
        }

        self::assertNull($this->provider->receivedQuery);
    }

    public function testThrowsWhenTheIndexDoesNotExist(): void
    {
        $this->expectException(IndexNotFoundException::class);
        $this->search->search(SearchQuery::create('missing', 'boots'));
    }

    public function testThrowsWhenTheIndexIsDisabled(): void
    {
        $this->index->enabled = false;

        $this->expectException(IndexDisabledException::class);
        $this->search->search(SearchQuery::create('siteSearch', 'boots'));
    }

    public function testRejectsFiltersTheProviderCannotHonour(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots')
            ->addFilter(SearchFilter::make('sectionId', FilterOperator::Equals, 3));

        $this->expectException(UnsupportedCapabilityException::class);
        $this->search->search($query);
    }

    public function testAcceptsFiltersWhenTheProviderSupportsThem(): void
    {
        $this->provider->supported = [ProviderCapability::Search, ProviderCapability::Filtering];

        $query = SearchQuery::create('siteSearch', 'boots')
            ->addFilter(SearchFilter::make('sectionId', FilterOperator::Equals, 3));

        $this->search->search($query);

        self::assertSame($query, $this->provider->receivedQuery);
    }

    public function testNormalizesTheResultAndReportsExecutionDetail(): void
    {
        $result = $this->search->search(SearchQuery::create('siteSearch', 'boots'));

        self::assertSame('siteSearch', $result->indexHandle);
        self::assertSame(StubProvider::class, $result->provider);
        self::assertGreaterThan(0, $result->executionTime);
    }

    public function testLeavesTheSiteUnresolvedSoTheIndexScopeApplies(): void
    {
        $this->search->search(SearchQuery::create('siteSearch', 'boots'));

        self::assertNull($this->provider->receivedQuery?->siteId);
    }




    public function testNormalizesPaginationOntoTheResult(): void
    {
        $query = SearchQuery::create('siteSearch', 'boots');
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
            $this->search->search(SearchQuery::create('siteSearch', 'boots'));
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

        $this->search->search(SearchQuery::create('siteSearch', 'winter  boots'));

        self::assertSame(['winter boots'], $observed);
    }
}
