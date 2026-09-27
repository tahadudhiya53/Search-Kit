<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use craft\elements\Category;
use craft\elements\Entry;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\enums\SortDirection;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\models\ParsedQuery;
use Tahadudhiya\SearchKit\models\QueryTerm;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\providers\MeilisearchProvider;
use Tahadudhiya\SearchKit\Tests\Support\FakeMeilisearchClient;

/**
 * What the Meilisearch provider asks the engine for, and what it makes of the answer. The engine
 * itself is covered by the integration suite, against a real server.
 */
class MeilisearchProviderTest extends TestCase
{
    private FakeMeilisearchClient $client;
    private MeilisearchProvider $provider;
    private SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeMeilisearchClient();
        // Site languages come from Craft, which the unit suite has no application for.
        $this->provider = new class(['url' => 'http://meilisearch.test']) extends MeilisearchProvider {
            protected function siteLanguages(SearchIndex $index): array
            {
                return ['en-GB', 'de-DE', 'cy-GB'];
            }
        };
        $this->provider->setClient($this->client);

        $this->index = new SearchIndex([
            'name' => 'Content',
            'handle' => 'content',
            'provider' => MeilisearchProvider::class,
        ]);

        $this->index->setFields([
            new SearchableField(['elementType' => Entry::class, 'handle' => 'title', 'weight' => 10]),
            new SearchableField(['elementType' => Entry::class, 'handle' => 'summary', 'weight' => 2]),
        ]);
    }

    public function testItNeverClaimsWhatMeilisearchCannotDo(): void
    {
        self::assertTrue($this->provider->supports(ProviderCapability::TypoTolerance));
        self::assertTrue($this->provider->supports(ProviderCapability::Highlighting));
        self::assertTrue($this->provider->supports(ProviderCapability::ResultExclusion));

        // Meilisearch has no `OR` between terms and no per-term partial matching, so a query asking
        // for either is refused upstream rather than quietly run as something else.
        self::assertFalse($this->provider->supports(ProviderCapability::TermAlternation));
        self::assertFalse($this->provider->supports(ProviderCapability::PartialMatching));
    }

    public function testItWritesTermsBackOutInMeilisearchSyntax(): void
    {
        $parsed = new ParsedQuery(['raw' => 'winter "alpine boots" -sandals']);
        $parsed->setTerms([
            QueryTerm::make('winter'),
            $this->phrase('alpine boots'),
            $this->excluded('sandals'),
        ]);

        $this->search($this->query('winter "alpine boots" -sandals')->setParsedQuery($parsed));

        self::assertSame('winter "alpine boots" -sandals', $this->searchBody()['q']);

        // Every term has to match, rather than Meilisearch dropping the ones that narrow too far.
        self::assertSame('all', $this->searchBody()['matchingStrategy']);
    }

    public function testItAsksOnlyForTheElementTypesTheIndexStillSearches(): void
    {
        $this->search($this->query('boots'));

        self::assertContains(
            'elementType IN ["craft\\\\elements\\\\Entry"]',
            $this->searchBody()['filter'],
        );
    }

    public function testItNarrowsToTheSiteInScopeAndNeverWiderThanTheIndex(): void
    {
        $this->index->siteId = 3;
        $this->search($this->query('boots'));

        self::assertContains('siteId = 3', $this->searchBody()['filter']);
    }

    public function testAnAllSitesIndexIsNotNarrowedToASite(): void
    {
        $this->search($this->query('boots'));

        foreach ($this->searchBody()['filter'] as $expression) {
            self::assertStringNotContainsString('siteId', $expression);
        }
    }

    public function testASearchNamingSeveralSitesIsNarrowedToThem(): void
    {
        $this->search($this->query('boots', ['sites' => [1, 3]]));

        self::assertContains('siteId IN [1, 3]', $this->searchBody()['filter']);
    }

    public function testARangeBecomesBothOfItsBounds(): void
    {
        $query = $this->query('boots');
        $query->addFilter(SearchFilter::make('id', FilterOperator::Between, [3, 9]));

        $this->search($query);

        self::assertContains('(elementId >= 3 AND elementId <= 9)', $this->searchBody()['filter']);
    }

    public function testARangeOnTextIsRefusedRatherThanAnsweredWithSomethingElse(): void
    {
        $query = $this->query('boots');
        $query->addFilter(SearchFilter::make('summary', FilterOperator::Between, [1, 5]));

        // Searchable content is stored as the text a Craft field reduced to, which Meilisearch
        // cannot order — so a range over it is a mistake rather than an empty result.
        $this->expectException(InvalidQueryException::class);
        $this->search($query);
    }

    public function testARangeOfOneValueStillHasBothBounds(): void
    {
        $query = $this->query('boots');
        $query->addFilter(SearchFilter::make('siteId', FilterOperator::Between, [3, 3]));

        $this->search($query);

        self::assertContains('(siteId >= 3 AND siteId <= 3)', $this->searchBody()['filter']);
    }

    public function testItCountsByAFieldWithoutAskingForResults(): void
    {
        $this->client->willAnswer('POST', 'indexes/content/search', [
            'hits' => [],
            'totalHits' => 12,
            'facetDistribution' => ['fields.title' => ['Boots' => 2, 'Sandals' => 7]],
        ]);

        $facets = $this->provider->facets(
            $this->query('boots', ['facets' => ['title', 'elementType']]),
            $this->index,
        );

        $body = $this->searchBody();

        self::assertSame(['fields.title', 'elementType'], $body['facets']);
        self::assertSame(0, $body['hitsPerPage']);

        // Commonest value first, and a field Meilisearch reported nothing for is simply empty.
        self::assertSame('title', $facets[0]->field);
        self::assertSame(['Sandals' => 7, 'Boots' => 2], $facets[0]->getCounts());
        self::assertTrue($facets[1]->isEmpty());
    }

    public function testItLeavesRemovedResultsOutOfTheSearchEntirely(): void
    {
        $query = $this->query('boots');
        $query->excludeElement(7, 2);
        $query->excludeElement(9);

        $this->search($query);

        self::assertContains('NOT (elementId = 7 AND siteId = 2)', $this->searchBody()['filter']);
        self::assertContains('elementId != 9', $this->searchBody()['filter']);
    }

    public function testFilterValuesCannotEscapeTheExpressionTheyAreIn(): void
    {
        $query = $this->query('boots');
        $query->addFilter(SearchFilter::make('summary', FilterOperator::Equals, 'wet" OR elementId = 1 OR "'));

        $this->search($query);

        self::assertContains(
            'fields.summary = "wet\" OR elementId = 1 OR \""',
            $this->searchBody()['filter'],
        );
    }

    public function testAFilterOnAFieldTheIndexDoesNotSearchIsAMistake(): void
    {
        $query = $this->query('boots');
        $query->addFilter(SearchFilter::make('price', FilterOperator::Equals, 10));

        $this->expectException(InvalidQueryException::class);
        $this->search($query);
    }

    public function testTextCannotBeComparedButAnIdentityNumberCan(): void
    {
        $numeric = $this->query('boots');
        $numeric->addFilter(SearchFilter::make('id', FilterOperator::GreaterThan, 5));
        $this->search($numeric);

        self::assertContains('elementId > 5', $this->searchBody()['filter']);

        $text = $this->query('boots');
        $text->addFilter(SearchFilter::make('title', FilterOperator::GreaterThan, 'b'));

        $this->expectException(InvalidQueryException::class);
        $this->search($text);
    }

    public function testAnElementTypeFilterTakesCraftsOwnReferenceHandle(): void
    {
        $query = $this->query('boots');
        $query->addFilter(SearchFilter::make('elementType', FilterOperator::Equals, Entry::refHandle()));

        $this->search($query);

        self::assertContains('elementType = "craft\\\\elements\\\\Entry"', $this->searchBody()['filter']);
    }

    public function testAnElementTypeTheIndexDoesNotCoverIsRefused(): void
    {
        $query = $this->query('boots');
        $query->addFilter(SearchFilter::make('elementType', FilterOperator::Equals, Category::refHandle()));

        $this->expectException(InvalidQueryException::class);
        $this->search($query);
    }

    public function testAPageIsAskedForAsAPageSoTheCountStaysExact(): void
    {
        $this->search($this->query('boots', ['limit' => 10, 'offset' => 20]));

        self::assertSame(3, $this->searchBody()['page']);
        self::assertSame(10, $this->searchBody()['hitsPerPage']);
    }

    public function testAWindowAtAShiftedOffsetIsReadFromTheTopAndCutHere(): void
    {
        $this->client->willAnswer('POST', 'indexes/content/search', [
            'totalHits' => 40,
            'hits' => array_map(
                static fn(int $id) => ['elementId' => $id, 'siteId' => 1, 'elementType' => Entry::class],
                range(1, 13),
            ),
        ]);

        $result = $this->search($this->query('boots', ['limit' => 10, 'offset' => 3]));

        self::assertSame(1, $this->searchBody()['page']);
        self::assertSame(13, $this->searchBody()['hitsPerPage']);
        self::assertSame(range(4, 13), $result->getElementIds());
        self::assertSame(40, $result->total);
    }

    public function testResultsAreOrderedByAFieldTheIndexActuallySearches(): void
    {
        $query = $this->query('boots');
        $query->addSort('title', SortDirection::Asc);

        $this->search($query);

        self::assertSame(['fields.title:asc'], $this->searchBody()['sort']);
    }

    public function testRelevanceContributesNoSortOfItsOwn(): void
    {
        $this->search($this->query('boots')->addSort('score'));

        self::assertArrayNotHasKey('sort', $this->searchBody());
    }

    public function testOrderingBySomethingTheIndexCannotSortByIsRefused(): void
    {
        $query = $this->query('boots');
        $query->addSort('price', SortDirection::Asc);

        $this->expectException(InvalidQueryException::class);
        $this->search($query);
    }

    public function testAnExcerptCannotCarryMarkupOutOfTheIndex(): void
    {
        $this->client->willAnswer('POST', 'indexes/content/search', [
            'totalHits' => 1,
            'hits' => [[
                'elementId' => 4,
                'siteId' => 1,
                'elementType' => Entry::class,
                '_rankingScore' => 0.75,
                '_formatted' => ['fields' => [
                    'title' => "Alpine \x02boots\x03 <script>alert(1)</script>",
                    'summary' => 'Nothing matched here',
                ]],
                '_matchesPosition' => ['fields.title' => [['start' => 7, 'length' => 5]]],
            ]],
        ]);

        $result = $this->search($this->query('boots', ['highlight' => true]));
        $hit = $result->hits[0];

        self::assertSame('Alpine boots alert(1)', $hit->getSnippet());
        self::assertSame('Alpine <mark>boots</mark> alert(1)', (string)$hit->getHighlight());
        self::assertSame(['title'], $hit->matchedFields);

        // A field Meilisearch did not mark is not an excerpt of anything.
        self::assertArrayNotHasKey('summary', $hit->highlights);
        self::assertSame(0.75, $hit->score);
    }

    public function testADocumentKeepsItsContentAwayFromItsIdentity(): void
    {
        $document = new SearchDocument([
            'elementId' => 12,
            'siteId' => 2,
            'elementType' => Entry::class,
            'indexHandle' => 'content',
        ]);
        $document->setField('title', 'Alpine boots');

        $this->provider->indexDocument($this->index, $document);

        self::assertSame([[
            'id' => '12_2',
            'elementId' => 12,
            'siteId' => 2,
            'elementType' => Entry::class,
            'fields' => ['title' => 'Alpine boots'],
        ]], $this->client->bodyFor('POST', 'indexes/content/documents'));
    }

    public function testAnElementTheIndexIsNotConfiguredForIsNotIndexed(): void
    {
        $this->expectException(ProviderException::class);

        $this->provider->indexDocument($this->index, new SearchDocument([
            'elementId' => 12,
            'siteId' => 2,
            'elementType' => Entry::class,
        ]));
    }

    public function testAnElementOutsideTheIndexScopeIsNotIndexed(): void
    {
        $this->index->siteId = 1;

        $document = new SearchDocument(['elementId' => 12, 'siteId' => 2, 'elementType' => Entry::class]);
        $document->setField('title', 'Alpine boots');

        $this->expectException(ProviderException::class);
        $this->provider->indexDocument($this->index, $document);
    }

    public function testThereIsNothingToDeleteFromAnIndexThatWasNeverCreated(): void
    {
        $this->client->willAnswer('GET', 'indexes/content', [], 404);

        $this->provider->deleteDocument($this->index, SearchDocument::forDeletion(3, 1, Entry::class, 'content'));

        self::assertSame([], $this->client->bodiesFor('DELETE', 'indexes/content/documents/3_1'));
    }

    public function testARebuildRecreatesTheIndexWithTheHeaviestFieldFirst(): void
    {
        $this->provider->rebuild($this->index);

        $settings = $this->client->bodyFor('PATCH', 'indexes/content/settings');

        self::assertSame(['fields.title', 'fields.summary'], $settings['searchableAttributes']);
        self::assertContains('fields.summary', $settings['filterableAttributes']);
        self::assertContains('elementId', $settings['filterableAttributes']);

        // Ordering is an instruction rather than a tiebreaker, so it outranks relevance.
        self::assertSame('sort', $settings['rankingRules'][0]);

        // Content is analysed in the languages its sites are written in — and only in the ones
        // Meilisearch knows, so a site in any other language cannot fail the rebuild.
        self::assertSame(
            [['attributePatterns' => ['fields.*'], 'locales' => ['en', 'de']]],
            $settings['localizedAttributes'],
        );
    }

    public function testARebuildDiscardsWhatTheIndexHeldBefore(): void
    {
        $this->provider->rebuild($this->index);

        self::assertCount(1, $this->client->bodiesFor('DELETE', 'indexes/content'));
    }

    public function testAWriteMeilisearchAcceptsAndThenGivesUpOnIsAFailure(): void
    {
        $this->client->willFailTask('POST', 'indexes/content/documents', 9, 'missing_document_id');

        $document = new SearchDocument(['elementId' => 12, 'siteId' => 2, 'elementType' => Entry::class]);
        $document->setField('title', 'Alpine boots');

        try {
            $this->provider->indexDocument($this->index, $document);
            self::fail('A write Meilisearch gave up on should not be reported as indexed.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('missing_document_id', $e->getMessage());
        }
    }

    public function testADeletionMeilisearchAcceptsAndThenGivesUpOnIsAFailure(): void
    {
        $this->client->willFailTask('DELETE', 'indexes/content/documents/3_1', 11, 'index_not_found');

        $this->expectException(ProviderException::class);
        $this->provider->deleteDocument($this->index, SearchDocument::forDeletion(3, 1, Entry::class, 'content'));
    }

    public function testARebuildSettlesWhatIsQueuedBeforeDiscardingTheIndex(): void
    {
        $this->provider->rebuild($this->index);

        $trail = $this->client->trail();
        $queued = array_search('GET tasks?indexUids=content&statuses=enqueued%2Cprocessing', $trail, true);
        $discarded = array_search('DELETE indexes/content', $trail, true);

        self::assertIsInt($queued);
        self::assertIsInt($discarded);
        // Anything still queued would otherwise be applied to the index this is about to build.
        self::assertLessThan($discarded, $queued);
    }

    public function testNothingIsDiscardedWhileMeilisearchWillNotSayWhatItHasQueued(): void
    {
        $this->client->willAnswer('GET', 'tasks?indexUids=content&statuses=enqueued%2Cprocessing', ['total' => 3]);

        try {
            $this->provider->rebuild($this->index);
            self::fail('A rebuild should not go ahead without knowing what is still queued.');
        } catch (ProviderException) {
            self::assertNotContains('DELETE indexes/content', $this->client->trail());
        }
    }

    public function testAMalformedAnswerIsAFailureRatherThanAnEmptyIndex(): void
    {
        $this->client->willAnswerRaw('POST', 'indexes/content/search', '<html>Gateway timed out</html>');

        $this->expectException(ProviderException::class);
        $this->search($this->query('boots'));
    }

    public function testASearchAnsweringNeitherResultsNorACountIsAFailure(): void
    {
        // Read leniently this is indistinguishable from an index that legitimately holds nothing.
        $this->client->willAnswer('POST', 'indexes/content/search', ['processingTimeMs' => 1]);

        $this->expectException(ProviderException::class);
        $this->search($this->query('boots'));
    }

    public function testASearchAnsweringResultsWithoutACountIsAFailure(): void
    {
        $this->client->willAnswer('POST', 'indexes/content/search', ['hits' => []]);

        $this->expectException(ProviderException::class);
        $this->search($this->query('boots'));
    }

    public function testAnAddressCarryingACredentialIsRefused(): void
    {
        $provider = new MeilisearchProvider(['url' => 'https://user:password@search.example.com']);

        self::assertFalse($provider->validate());
        self::assertNotSame([], $provider->getErrors('url'));

        foreach (['http://localhost:7700', 'https://search.example.com', 'http://host:7700'] as $url) {
            $allowed = new MeilisearchProvider(['url' => $url]);

            self::assertTrue($allowed->validate(), "“{$url}” should be a usable address.");
        }
    }

    public function testThePrefixKeepsTwoEnvironmentsApartOnOneServer(): void
    {
        $this->provider->indexPrefix = 'staging_';

        self::assertSame('staging_content', $this->provider->indexUid($this->index));
    }

    public function testNothingIsShownToADeveloperThatThisProviderDidNotName(): void
    {
        $shown = $this->provider->diagnostics([
            'meilisearchQuery' => 'boots',
            'filter' => ['siteId = 1'],
            'apiKey' => 'secret',
            'url' => 'http://meilisearch.test',
        ]);

        self::assertSame(['meilisearchQuery' => 'boots', 'filter' => ['siteId = 1']], $shown);
    }

    public function testAKeyMustNameAnEnvironmentVariableRatherThanHoldOne(): void
    {
        $provider = new MeilisearchProvider([
            'url' => 'http://meilisearch.test',
            'apiKey' => 'a-real-looking-master-key',
        ]);

        self::assertFalse($provider->validate());
        self::assertNotSame([], $provider->getErrors('apiKey'));
    }

    public function testAnAddressThatIsNotAnHttpUrlIsRefused(): void
    {
        $provider = new MeilisearchProvider(['url' => 'meilisearch.test']);

        self::assertFalse($provider->validate());
        self::assertNotSame([], $provider->getErrors('url'));
    }

    public function testASettingNamingAnEnvironmentVariableThatIsNotSetIsRefused(): void
    {
        $provider = new MeilisearchProvider(['url' => '$SEARCHKIT_NO_SUCH_VARIABLE']);

        self::assertFalse($provider->validate());
        self::assertNotSame([], $provider->getErrors('url'));
    }

    public function testARefusalNamesWhatMeilisearchCalledItAndNothingElse(): void
    {
        $this->client->willAnswer('POST', 'indexes/content/search', [
            'message' => 'Attribute `secret` is not filterable. Available: …',
            'code' => 'invalid_search_filter',
        ], 400);

        try {
            $this->search($this->query('boots'));
            self::fail('A refused search should not come back as a result.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('invalid_search_filter', $e->getMessage());
            self::assertStringNotContainsString('Available', $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function query(string $text, array $params = []): SearchQuery
    {
        return SearchQuery::create('content', $text, $params);
    }

    private function search(SearchQuery $query): \Tahadudhiya\SearchKit\models\SearchResult
    {
        return $this->provider->search($query, $this->index);
    }

    /**
     * @return array<string,mixed>
     */
    private function searchBody(): array
    {
        return $this->client->bodyFor('POST', 'indexes/content/search');
    }

    private function phrase(string $text): QueryTerm
    {
        $term = QueryTerm::make($text);
        $term->phrase = true;

        return $term;
    }

    private function excluded(string $text): QueryTerm
    {
        $term = QueryTerm::make($text);
        $term->excluded = true;

        return $term;
    }
}
