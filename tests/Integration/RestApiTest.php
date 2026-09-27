<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\models\ApiKey;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * What the REST API answers with, and what it refuses. It runs the same search PHP and Twig run,
 * so what is proved here is the request it accepts and the data it gives back.
 */
class RestApiTest extends ContentTestCase
{
    private const TERM = 'zqxapirest';

    private SearchIndex $index;
    private ApiKey $key;

    /** @var ApiKey[] */
    private array $createdKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );

        foreach (['Zqxapirest Alpha', 'Zqxapirest Beta', 'Zqxapirest Gamma'] as $title) {
            $this->indexed($this->createEntry($title));
        }

        $this->key = $this->createKey();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdKeys as $key) {
            $this->plugin()->getApiKeys()->deleteKey($key);
        }

        $this->createdKeys = [];

        parent::tearDown();
    }

    public function testAnswersWithTheSearchAsPlainData(): void
    {
        $payload = $this->search();

        self::assertSame($this->index->handle, $payload['index']);
        self::assertSame(3, $payload['total']);
        self::assertSame(1, $payload['page']);
        self::assertFalse($payload['hasNextPage']);
        self::assertCount(3, $payload['hits']);

        $hit = $payload['hits'][0];

        self::assertIsInt($hit['elementId']);
        self::assertSame($this->sectionSiteId(), $hit['siteId']);
        // The same vocabulary a filter uses, rather than a class name.
        self::assertSame('entry', $hit['elementType']);
        self::assertStringContainsString('Zqxapirest', (string)$hit['title']);
        self::assertArrayHasKey('finalScore', $hit);
        self::assertFalse($hit['pinned']);
    }

    public function testReportsWhereInTheResultsAWindowIs(): void
    {
        $page = $this->search(['limit' => 2]);

        self::assertSame(3, $page['total']);
        self::assertCount(2, $page['hits']);
        self::assertSame(2, $page['pageCount']);
        self::assertTrue($page['hasNextPage']);
        self::assertFalse($page['hasPreviousPage']);

        // Request parameters arrive as text, which is what a page number has to be readable from.
        $second = $this->search(['limit' => '2', 'page' => '2']);

        self::assertSame(2, $second['page']);
        self::assertSame(2, $second['offset']);
        self::assertCount(1, $second['hits']);
        self::assertTrue($second['hasPreviousPage']);
    }

    public function testFiltersAndOrderingReachTheSearch(): void
    {
        $ordered = $this->search(['orderBy' => 'title asc']);

        self::assertSame(
            ['Zqxapirest Alpha', 'Zqxapirest Beta', 'Zqxapirest Gamma'],
            array_map(static fn(array $hit) => $hit['title'], $ordered['hits']),
        );

        $filtered = $this->search(['filters' => ['section' => $this->section()->handle]]);
        self::assertSame(3, $filtered['total']);

        $excluded = $this->search(['filters' => ['section' => ['neq' => $this->section()->handle]]]);
        self::assertSame(0, $excluded['total']);
    }

    public function testCountsAreAnsweredAsPlainDataFromACommaSeparatedParameter(): void
    {
        // A query string carries a list as text, which is how a client asks for more than one.
        $answer = $this->search(['facets' => 'elementType,sectionId', 'limit' => 1]);

        self::assertCount(1, $answer['hits']);
        self::assertSame(['elementType', 'sectionId'], array_column($answer['facets'], 'field'));
        self::assertSame(
            $answer['total'],
            array_sum(array_column($answer['facets'][0]['values'], 'count')),
        );
    }

    public function testSiteListsAndRangesArriveAsTextAndAreStillVetted(): void
    {
        // The index under test covers one site, which is the only site it may be asked for.
        $siteIds = [(string)$this->sectionSiteId()];
        $ids = $this->search()['hits'];
        $elementIds = array_column($ids, 'elementId');
        sort($elementIds);

        // A query string carries both as text, and they mean what they mean in PHP and Twig.
        $ranged = $this->search([
            'sites' => implode(',', $siteIds),
            'filters' => ['id' => ['between' => [(string)$elementIds[0], (string)$elementIds[1]]]],
            'orderBy' => 'id asc',
        ]);

        self::assertSame([$elementIds[0], $elementIds[1]], array_column($ranged['hits'], 'elementId'));

        // A range running backwards is refused in the same words the search API refuses it in.
        try {
            $this->search(['filters' => ['id' => ['between' => ['500', '100']]]]);
            self::fail('A range running backwards should be refused.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('filters', $e->getErrors());
        }

        // A site the index does not cover cannot be named, however it is written.
        try {
            $this->search(['sites' => '99999']);
            self::fail('A site that does not exist should be refused.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('siteId', $e->getErrors());
        }
    }

    public function testAnIndexAKeyDoesNotCoverIsReportedAsThoughItWereNotThere(): void
    {
        $scoped = $this->createKey([(int)$this->persistIndex($this->newIndex())->id]);

        $refused = $this->failure(fn() => $this->plugin()->getApi()->search($scoped, $this->params()));
        $unknown = $this->failure(fn() => $this->plugin()->getApi()->search($this->key, [
            'index' => 'noSuchIndexAnywhere',
            'q' => self::TERM,
        ]));

        // Word for word the same, so a key can never be used to find out which indexes exist.
        self::assertInstanceOf(IndexNotFoundException::class, $refused);
        self::assertInstanceOf(IndexNotFoundException::class, $unknown);
    }

    public function testRefusesWhatTheSearchApiDoesNotAccept(): void
    {
        // Unknown parameters, an empty query, and a limit past the maximum are all refusals.
        self::assertInstanceOf(InvalidQueryException::class, $this->failure(fn() => $this->search(['nonsense' => 1])));
        self::assertInstanceOf(InvalidQueryException::class, $this->failure(fn() => $this->search([], '')));
        self::assertInstanceOf(
            InvalidQueryException::class,
            $this->failure(fn() => $this->search(['limit' => SearchQuery::MAX_LIMIT + 1])),
        );

        // The API is answered anonymously, so asking for anything but published content is refused
        // where it can be explained rather than at the end of a search.
        self::assertInstanceOf(InvalidQueryException::class, $this->failure(fn() => $this->search(['status' => 'disabled'])));
    }

    public function testReadsABooleanTheWayARequestCanCarryOne(): void
    {
        self::assertNotSame([], $this->search(['highlight' => 'true'])['hits'][0]['snippets']);
        self::assertSame([], $this->search(['highlight' => 'false'])['hits'][0]['snippets']);
        self::assertSame([], $this->search(['highlight' => false])['hits'][0]['snippets']);

        self::assertInstanceOf(InvalidQueryException::class, $this->failure(fn() => $this->search(['highlight' => 'maybe'])));
    }

    public function testAnIndexNeedsNaming(): void
    {
        self::assertInstanceOf(
            InvalidQueryException::class,
            $this->failure(fn() => $this->plugin()->getApi()->search($this->key, ['q' => self::TERM])),
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function search(array $params = [], string $text = self::TERM): array
    {
        return $this->plugin()->getApi()->search($this->key, $this->params($params, $text));
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function params(array $params = [], string $text = self::TERM): array
    {
        return array_merge(['index' => $this->index->handle, 'q' => $text], $params);
    }

    /**
     * @param int[] $indexIds
     */
    private function createKey(array $indexIds = []): ApiKey
    {
        $key = new ApiKey(['name' => 'REST test key', 'indexIds' => $indexIds]);

        self::assertNotNull($this->plugin()->getApiKeys()->createKey($key));
        $this->createdKeys[] = $key;

        return $key;
    }

    private function failure(callable $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('The API accepted something it should have refused.');
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
