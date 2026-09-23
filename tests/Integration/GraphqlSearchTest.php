<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tahadudhiya\SearchKit\gql\resolvers\SearchResolver;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * The GraphQL search runs through the same search service PHP, Twig and REST run through. What is
 * proved here is what the schema decides: which indexes it may search, which sites, and how little
 * of an element a hit gives away.
 */
class GraphqlSearchTest extends ContentTestCase
{
    private const TERM = 'zqxapigraph';

    private SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );

        foreach (['Zqxapigraph Alpha', 'Zqxapigraph Beta', 'Zqxapigraph Gamma'] as $title) {
            $this->indexed($this->createEntry($title));
        }
    }

    protected function tearDown(): void
    {
        Craft::$app->getGql()->flushCaches();
        Craft::$app->getGql()->setActiveSchema(null);

        parent::tearDown();
    }

    public function testASearchRunsAndReportsWhatItFound(): void
    {
        $result = $this->execute(
            "{ searchKitSearch(index: \"{$this->index->handle}\", q: \"" . self::TERM . "\") {
                index total page hasNextPage hits { elementId siteId elementType finalScore pinned }
            } }",
            $this->schema(),
        );

        self::assertArrayNotHasKey('errors', $result);

        $search = $result['data']['searchKitSearch'];

        self::assertSame($this->index->handle, $search['index']);
        self::assertSame(3, $search['total']);
        self::assertCount(3, $search['hits']);
        self::assertSame('entry', $search['hits'][0]['elementType']);
        self::assertSame($this->sectionSiteId(), $search['hits'][0]['siteId']);
    }

    public function testAHitGivesAwayNothingOfTheElementBehindIt(): void
    {
        // Content is the element's to give out through its own queries, where the schema decides
        // which fields may be read. A hit names the element and how it ranked, and nothing else.
        $result = $this->execute(
            "{ searchKitSearch(index: \"{$this->index->handle}\", q: \"" . self::TERM . "\") { hits { title } } }",
            $this->schema(),
        );

        self::assertArrayHasKey('errors', $result);
        self::assertStringContainsString('Cannot query field', $result['errors'][0]['message']);
    }

    public function testAnIndexTheSchemaDoesNotNameIsReportedAsThoughItWereNotThere(): void
    {
        $other = $this->persistIndex($this->newIndex());

        $result = $this->execute(
            "{ searchKitSearch(index: \"{$this->index->handle}\", q: \"" . self::TERM . "\") { total } }",
            $this->schema($other),
        );

        self::assertArrayHasKey('errors', $result);
        self::assertStringContainsString('No search index exists', $result['errors'][0]['message']);
    }

    public function testASchemaNamingNoIndexIsNotOfferedTheSearchAtAll(): void
    {
        $result = $this->execute(
            "{ searchKitSearch(index: \"{$this->index->handle}\", q: \"" . self::TERM . "\") { total } }",
            $this->schema(null, false),
        );

        self::assertArrayHasKey('errors', $result);
        self::assertStringContainsString('Cannot query field', $result['errors'][0]['message']);
    }

    public function testASiteTheSchemaDoesNotAllowCannotBeSearched(): void
    {
        $result = $this->execute(
            "{ searchKitSearch(index: \"{$this->index->handle}\", q: \"" . self::TERM . "\") { total } }",
            $this->schema(null, true, false),
        );

        self::assertArrayHasKey('errors', $result);
        self::assertStringContainsString('does not have access to the site', $result['errors'][0]['message']);
    }

    public function testFiltersAndPagingReachTheSearch(): void
    {
        $handle = $this->section()->handle;

        $filtered = $this->execute(
            "{ searchKitSearch(
                index: \"{$this->index->handle}\",
                q: \"" . self::TERM . "\",
                limit: 2,
                orderBy: \"title asc\",
                filters: [{ field: \"section\", operator: \"in\", value: [\"{$handle}\"] }]
            ) { total pageCount hasNextPage hits { elementId } } }",
            $this->schema(),
        );

        self::assertArrayNotHasKey('errors', $filtered);
        self::assertSame(3, $filtered['data']['searchKitSearch']['total']);
        self::assertSame(2, $filtered['data']['searchKitSearch']['pageCount']);
        self::assertCount(2, $filtered['data']['searchKitSearch']['hits']);
    }

    public function testTheResultsCanBeCountedByAField(): void
    {
        $result = $this->execute(
            "{ searchKitSearch(
                index: \"{$this->index->handle}\",
                q: \"" . self::TERM . "\",
                limit: 1,
                facets: [\"elementType\"]
            ) { total facets { field values { value count } } } }",
            $this->schema(),
        );

        self::assertArrayNotHasKey('errors', $result);

        $facets = $result['data']['searchKitSearch']['facets'];

        self::assertSame('elementType', $facets[0]['field']);
        self::assertSame(
            $result['data']['searchKitSearch']['total'],
            array_sum(array_column($facets[0]['values'], 'count')),
        );
    }

    public function testSiteListsAndRangesReachTheSearch(): void
    {
        $handle = (string)Craft::$app->getSites()->getSiteById($this->sectionSiteId())?->handle;
        $ids = $this->elementIds();

        $result = $this->execute(
            "{ searchKitSearch(
                index: \"{$this->index->handle}\",
                q: \"" . self::TERM . "\",
                sites: [\"{$handle}\"],
                orderBy: \"id asc\",
                filters: [{ field: \"id\", operator: \"between\", value: [{$ids[0]}, {$ids[1]}] }]
            ) { total hits { elementId siteId } } }",
            $this->schema(),
        );

        self::assertArrayNotHasKey('errors', $result);
        self::assertSame(2, $result['data']['searchKitSearch']['total']);
        self::assertSame(
            [$ids[0], $ids[1]],
            array_column($result['data']['searchKitSearch']['hits'], 'elementId'),
        );
    }

    public function testARangeRunningBackwardsIsRefused(): void
    {
        $result = $this->execute(
            "{ searchKitSearch(
                index: \"{$this->index->handle}\",
                q: \"" . self::TERM . "\",
                filters: [{ field: \"id\", operator: \"between\", value: [900, 100] }]
            ) { total } }",
            $this->schema(),
        );

        self::assertArrayHasKey('errors', $result);
        self::assertStringContainsString('lowest value first', $result['errors'][0]['message']);
    }

    public function testASiteListTheSchemaDoesNotAllowCannotBeSearched(): void
    {
        $handle = (string)Craft::$app->getSites()->getSiteById($this->sectionSiteId())?->handle;

        $result = $this->execute(
            "{ searchKitSearch(
                index: \"{$this->index->handle}\",
                q: \"" . self::TERM . "\",
                sites: [\"{$handle}\"]
            ) { total } }",
            $this->schema(null, true, false),
        );

        self::assertArrayHasKey('errors', $result);
        self::assertStringContainsString('does not have access to the site', $result['errors'][0]['message']);
    }

    /**
     * @return int[] The two lowest element IDs under test, which a range is written around.
     */
    private function elementIds(): array
    {
        $result = $this->execute(
            "{ searchKitSearch(index: \"{$this->index->handle}\", q: \"" . self::TERM . "\", orderBy: \"id asc\") "
            . '{ hits { elementId } } }',
            $this->schema(),
        );

        $ids = array_column($result['data']['searchKitSearch']['hits'], 'elementId');

        self::assertGreaterThan(2, count($ids), 'This test needs more than two results to range over.');

        return [$ids[0], $ids[1]];
    }

    public function testAnOperatorTakingOneValueIsNotGivenSeveral(): void
    {
        $result = $this->execute(
            "{ searchKitSearch(
                index: \"{$this->index->handle}\",
                q: \"" . self::TERM . "\",
                filters: [{ field: \"section\", operator: \"eq\", value: [\"a\", \"b\"] }]
            ) { total } }",
            $this->schema(),
        );

        self::assertArrayHasKey('errors', $result);
        self::assertStringContainsString('expects exactly one value', $result['errors'][0]['message']);
    }

    /**
     * @return array<string,mixed>
     */
    private function execute(string $query, GqlSchema $schema): array
    {
        // Every execution starts from the schema it was given, rather than one another test left.
        Craft::$app->getGql()->flushCaches();

        // In debug mode, which is what a development environment runs, a field that is not there
        // is reported rather than quietly resolving to nothing.
        return Craft::$app->getGql()->executeQuery($schema, $query, null, null, true);
    }

    /**
     * A schema naming one index and every site, or whatever part of that a test needs.
     */
    private function schema(?SearchIndex $index = null, bool $withIndex = true, bool $withSites = true): GqlSchema
    {
        $scope = [];

        if ($withIndex) {
            $scope[] = SearchResolver::schemaComponent($index ?? $this->index) . ':read';
        }

        if ($withSites) {
            foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
                $scope[] = "sites.{$site->uid}:read";
            }
        }

        return new GqlSchema([
            'name' => 'SearchKit test schema',
            'uid' => StringHelper::UUID(),
            'scope' => $scope,
        ]);
    }

    /**
     * Craft indexes an element's keywords itself on save; this makes the test independent of when.
     */
    private function indexed(Entry $entry): Entry
    {
        Craft::$app->getSearch()->indexElementAttributes($entry, ['title']);

        return $entry;
    }
}
