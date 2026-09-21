<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\QueryTerm;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchDebug;
use Tahadudhiya\SearchKit\models\SearchExclusion;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\models\SearchRule;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Normalization;
use Tahadudhiya\SearchKit\services\RuleEngine;
use Tahadudhiya\SearchKit\services\Search;
use Tahadudhiya\SearchKit\Tests\Support\StubIndexes;
use Tahadudhiya\SearchKit\Tests\Support\StubProvider;
use Tahadudhiya\SearchKit\Tests\Support\StubProviders;
use Tahadudhiya\SearchKit\Tests\Support\StubRules;
use Tahadudhiya\SearchKit\Tests\Support\StubSearchableFields;
use Tahadudhiya\SearchKit\Tests\Support\StubSuggestions;

/**
 * What a search records about itself, exercised through the real search service so that what is
 * asserted here is what the pipeline actually produced.
 */
class SearchDebugTest extends TestCase
{
    private const ELEMENT_TYPE = 'craft\\elements\\Entry';

    private StubProvider $provider;
    private StubSuggestions $suggestions;
    private StubRules $rules;
    private SearchIndex $index;
    private Search $search;

    protected function setUp(): void
    {
        $this->provider = new StubProvider();
        $this->provider->supported = [
            ProviderCapability::Search,
            ProviderCapability::Filtering,
            ProviderCapability::ResultExclusion,
        ];

        $this->index = new SearchIndex([
            'id' => 1,
            'name' => 'Site Search',
            'handle' => 'siteSearch',
            'provider' => CraftProvider::class,
            'siteId' => 1,
        ]);
        $this->index->setFields([
            new SearchableField(['elementType' => self::ELEMENT_TYPE, 'handle' => 'title', 'weight' => 100]),
        ]);

        $providers = new StubProviders();
        $providers->provider = $this->provider;

        $indexes = new StubIndexes();
        $indexes->indexes = [$this->index];

        $normalization = new Normalization();
        $normalization->language = 'en-US';

        $this->rules = new StubRules();
        $this->rules->setNormalization($normalization);

        $engine = new RuleEngine();
        $engine->setRules($this->rules);
        $engine->setNormalization($normalization);

        $this->search = new Search();
        $this->search->setIndexes($indexes);
        $this->search->setProviders($providers);
        $this->search->setSearchableFields(new StubSearchableFields());
        $this->search->setQueryPipeline(SearchServiceTest::pipeline());
        $this->suggestions = new StubSuggestions();
        $this->search->setSuggestions($this->suggestions);
        $this->search->setRuleEngine($engine);
    }

    public function testASearchRecordsNothingUnlessItIsAskedTo(): void
    {
        $this->provider->result = new SearchResult(['hits' => [$this->hit(11)], 'total' => 1]);

        $result = $this->search->search(SearchQuery::create('siteSearch', 'coffee'));

        self::assertNull($result->debug);
    }

    public function testDebuggingCannotBeTurnedOnByASearchParameter(): void
    {
        $this->expectExceptionMessage('“debug” is not a search parameter.');

        SearchQuery::create('siteSearch', 'coffee', ['debug' => true]);
    }

    public function testTheContextDescribesTheQueryTheProviderWasGiven(): void
    {
        $this->provider->result = new SearchResult(['hits' => [$this->hit(11)], 'total' => 1]);

        $debug = $this->debugSearch('The Coffee');

        self::assertSame('The Coffee', $debug->originalRaw);
        self::assertSame('the coffee', $debug->originalNormalized);
        self::assertSame(['the'], $debug->removedStopWords);

        // A stop word is dropped before the provider sees the query, so what was searched for is
        // reported apart from what was typed.
        self::assertSame('coffee', $debug->effectiveNormalized);
        self::assertSame(['coffee'], array_column($debug->terms, 'text'));
    }

    public function testTheContextNamesTheProviderAndWhatItCanDo(): void
    {
        $this->provider->result = new SearchResult(['hits' => [$this->hit(11)], 'total' => 1]);

        $debug = $this->debugSearch('coffee');

        self::assertSame(StubProvider::class, $debug->provider);
        self::assertContains('search', $debug->capabilities);
        self::assertNotContains('fieldWeighting', $debug->capabilities);
        self::assertFalse($debug->weightsApplied);
        self::assertSame('siteSearch', $debug->indexHandle);
        self::assertSame(1, $debug->siteId);
        self::assertSame([self::ELEMENT_TYPE => ['title' => 100]], $debug->fieldWeights);
    }

    public function testEveryCallToTheProviderIsRecordedWhereItWasMade(): void
    {
        $this->provider->result = new SearchResult([
            'hits' => [$this->hit(11)],
            'total' => 1,
            'metadata' => ['craftQuery' => 'coffee'],
        ]);

        $debug = $this->debugSearch('coffee');

        self::assertCount(1, $debug->executions);
        self::assertSame(SearchDebug::PURPOSE_SEARCH, $debug->executions[0]['purpose']);
        self::assertSame(1, $debug->executions[0]['returned']);
        self::assertSame(1, $debug->executions[0]['total']);
        // The stub provider declares nothing safe to show, so none of its metadata is reported.
        self::assertSame([], $debug->executions[0]['request']);
        self::assertGreaterThan(0.0, $debug->executions[0]['time']);
    }

    public function testEveryStageOfThePipelineIsTimed(): void
    {
        $this->provider->result = new SearchResult(['hits' => [$this->hit(11)], 'total' => 1]);

        $debug = $this->debugSearch('coffee');

        foreach (['setup', 'parse', 'rules', 'provider', 'elements'] as $stage) {
            self::assertArrayHasKey($stage, $debug->timings);
        }

        self::assertGreaterThan(0.0, $debug->getTotalTime());
    }

    public function testAResultThatCouldNotBeShownIsRecordedWhereItWasDropped(): void
    {
        // Nothing can be loaded without a Craft application behind it, which is the same path a
        // result takes when it is gone from the site and status the search ran in.
        $this->provider->result = new SearchResult(['hits' => [$this->hit(11)], 'total' => 1]);

        $debug = $this->debugSearch('coffee');

        self::assertSame([], $debug->results);
        self::assertCount(1, $debug->exclusions);
        self::assertSame(11, $debug->exclusions[0]->elementId);
        self::assertSame(SearchExclusion::NOT_AVAILABLE, $debug->exclusions[0]->reason);
        self::assertNull($debug->exclusions[0]->ruleId);
    }

    public function testAResultARuleRemovedIsExplainedByTheRuleThatRemovedIt(): void
    {
        $this->provider->result = new SearchResult(['hits' => [], 'total' => 0]);
        $this->rules->rules = [$this->hideRule(22)];

        $debug = $this->debugSearch('coffee');

        self::assertCount(1, $debug->exclusions);
        self::assertSame(22, $debug->exclusions[0]->elementId);
        self::assertSame(self::ELEMENT_TYPE, $debug->exclusions[0]->elementType);
        self::assertSame(SearchExclusion::HIDDEN_BY_RULE, $debug->exclusions[0]->reason);
        self::assertSame(7, $debug->exclusions[0]->ruleId);
    }

    public function testEveryRuleConsideredIsReportedWithWhatItDid(): void
    {
        $this->provider->result = new SearchResult(['hits' => [$this->hit(11)], 'total' => 1]);
        $this->rules->rules = [$this->hideRule(22), $this->boostRule(11, 5.0, 'tea')];

        $debug = $this->debugSearch('coffee');

        self::assertSame(2, $debug->plan['rulesConsidered']);
        self::assertSame(1, $debug->plan['rulesMatched']);
        self::assertFalse($debug->plan['reordered']);
    }

    public function testACorrectionLeavesWhatWasTypedStandingBesideIt(): void
    {
        $this->provider->result = new SearchResult(['hits' => [], 'total' => 0]);

        $corrected = new ParsedQuery(['raw' => 'iphon', 'normalized' => 'iphon']);
        $term = QueryTerm::make('iphone', 'iphon');
        $term->corrected = true;
        $corrected->setTerms([$term]);
        $corrected->corrected = true;

        $this->suggestions->correction = $corrected;
        $this->provider->results = [
            new SearchResult(['hits' => [], 'total' => 0]),
            new SearchResult(['hits' => [], 'total' => 3]),
        ];

        $debug = $this->debugSearch('iphon');

        // What was typed survives the search that went looking for something else.
        self::assertSame('iphon', $debug->originalRaw);
        self::assertSame('iphon', $debug->originalNormalized);

        // And what was searched for instead is reported in its own right.
        self::assertSame('iphone', $debug->correctedTo);
        self::assertSame('iphone', $debug->effectiveNormalized);
        self::assertSame(['iphone'], array_column($debug->terms, 'text'));
        self::assertTrue($debug->terms[0]['corrected']);
        self::assertSame('iphon', $debug->terms[0]['original']);

        self::assertSame(
            [SearchDebug::PURPOSE_SEARCH, SearchDebug::PURPOSE_CORRECTION],
            array_column($debug->executions, 'purpose'),
        );
    }

    public function testNothingAProviderReportsIsShownUnlessItSaysSo(): void
    {
        // A provider that puts its own request on the result, credentials and all.
        $this->provider->result = new SearchResult([
            'hits' => [],
            'total' => 0,
            'metadata' => [
                'apiKey' => 'sk-live-secret',
                'authorization' => 'Bearer secret-token',
                'password' => 'hunter2',
                'endpoint' => 'https://user:pass@search.example.com',
                'query' => 'coffee',
            ],
        ]);

        $debug = $this->debugSearch('coffee');

        // The base provider declares nothing safe, so none of it reaches the page.
        self::assertSame([], $debug->executions[0]['request']);
        self::assertStringNotContainsString('secret', json_encode($debug->executions, JSON_THROW_ON_ERROR));
    }

    public function testACraftProviderShowsItsQueryAndNothingElseItWasGiven(): void
    {
        $diagnostics = (new CraftProvider())->diagnostics([
            'craftQuery' => 'coffee*',
            'elementTypes' => [self::ELEMENT_TYPE],
            'orderBy' => ['score'],
            'apiKey' => 'sk-live-secret',
            'settings' => ['password' => 'hunter2'],
        ]);

        self::assertSame(['craftQuery', 'elementTypes', 'orderBy'], array_keys($diagnostics));
        self::assertSame('coffee*', $diagnostics['craftQuery']);
    }

    private function debugSearch(string $text): SearchDebug
    {
        $query = SearchQuery::create('siteSearch', $text);
        $query->startDebug();

        $debug = $this->search->search($query)->debug;
        self::assertNotNull($debug);

        return $debug;
    }

    private function hit(int $elementId): SearchHit
    {
        return new SearchHit([
            'elementId' => $elementId,
            'siteId' => 1,
            'elementType' => self::ELEMENT_TYPE,
        ]);
    }

    private function boostRule(int $elementId, float $amount, string $matchValue = 'coffee'): SearchRule
    {
        $rule = new SearchRule([
            'id' => 3,
            'indexId' => 1,
            'name' => 'Boost',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => $matchValue,
            'siteId' => 1,
        ]);

        $rule->setActions([new RuleAction([
            'type' => RuleActionType::Boost,
            'elementId' => $elementId,
            'elementType' => self::ELEMENT_TYPE,
            'amount' => $amount,
            'siteId' => 1,
        ])]);

        return $rule;
    }

    private function hideRule(int $elementId): SearchRule
    {
        $rule = new SearchRule([
            'id' => 7,
            'indexId' => 1,
            'name' => 'Hide',
            'matchType' => RuleMatchType::Exact,
            'matchValue' => 'coffee',
            'siteId' => 1,
        ]);

        $rule->setActions([new RuleAction([
            'type' => RuleActionType::Hide,
            'elementId' => $elementId,
            'elementType' => self::ELEMENT_TYPE,
            'siteId' => 1,
        ])]);

        return $rule;
    }
}
