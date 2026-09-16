<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\Tests\Support\BareHitProvider;

/**
 * Exercises the public search API against content this test creates, so what it asserts about
 * filtering, sorting, pagination and excerpts is exact rather than whatever the project holds.
 */
class SearchApiTest extends ContentTestCase
{
    private const TERM = 'zqxwoodland';

    private SearchIndex $index;

    /** @var Entry[] */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        BareHitProvider::reset();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->sectionSiteId(),
        );

        foreach (['Zqxwoodland Summer Hat', 'Zqxwoodland Winter Boots', 'Zqxwoodland Winter Jacket'] as $title) {
            $this->entries[] = $this->indexed($this->createEntry($title));
        }
    }

    public function testFiltersBySection(): void
    {
        $section = $this->section();

        self::assertSame(3, $this->search(['filters' => ['section' => $section->handle]])->total);
        self::assertSame(0, $this->search(['filters' => ['section' => ['neq' => $section->handle]]])->total);
    }

    public function testFiltersByEntryType(): void
    {
        // By ID rather than handle: a project may well have two entry types sharing a handle.
        $entryType = $this->section()->getEntryTypes()[0];

        self::assertSame(3, $this->search(['filters' => ['typeId' => $entryType->id]])->total);
        self::assertSame(0, $this->search(['filters' => ['typeId' => ['neq' => $entryType->id]]])->total);
    }

    public function testFiltersByElementType(): void
    {
        self::assertSame(3, $this->search(['filters' => ['elementType' => 'entry']])->total);

        // Ruling every element type out is an empty result, not a failure.
        $result = $this->search(['filters' => ['elementType' => ['neq' => 'entry']]]);

        self::assertSame(0, $result->total);
        self::assertTrue($result->isEmpty());
    }

    public function testFiltersOnAnElementAttribute(): void
    {
        $slug = $this->entries[1]->slug;

        $result = $this->search(['filters' => ['slug' => $slug]]);

        self::assertSame(1, $result->total);
        self::assertSame($this->entries[1]->id, $result->hits[0]->elementId);
    }

    public function testSortsByAnElementAttribute(): void
    {
        $ascending = $this->titles($this->search(['orderBy' => 'title asc']));

        self::assertSame(['Zqxwoodland Summer Hat', 'Zqxwoodland Winter Boots', 'Zqxwoodland Winter Jacket'], $ascending);
        self::assertSame(array_reverse($ascending), $this->titles($this->search(['orderBy' => 'title desc'])));
    }

    public function testCraftOnlyScoresResultsItWasAskedToRank(): void
    {
        foreach ($this->search()->hits as $hit) {
            self::assertGreaterThan(0.0, $hit->score);
        }

        // Craft works a score out only while ranking by it, so ordering another way leaves none.
        foreach ($this->search(['orderBy' => 'title asc'])->hits as $hit) {
            self::assertSame(0.0, $hit->score);
        }
    }

    public function testPaginatesThroughEveryPage(): void
    {
        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $result = $this->search(['limit' => 1, 'page' => $page, 'orderBy' => 'title asc']);

            self::assertSame(3, $result->total);
            self::assertSame(3, $result->getPageCount());
            self::assertSame($page, $result->getPage());
            self::assertSame($page < 3, $result->getHasNextPage());
            self::assertSame($page > 1, $result->getHasPreviousPage());
            self::assertCount(1, $result->hits);

            $seen[] = $result->hits[0]->elementId;
        }

        self::assertCount(3, array_unique($seen));
    }

    public function testHighlightingIsOnlyDoneWhenAskedFor(): void
    {
        foreach ($this->search()->hits as $hit) {
            self::assertSame([], $hit->highlights);
            self::assertSame([], $hit->matchedFields);
            self::assertNull($hit->getSnippet());
        }
    }

    public function testReportsWhatEachHitMatchedOn(): void
    {
        $result = $this->plugin()->getSearch()->search(
            SearchQuery::create($this->index->handle, 'Zqxwoodland winter', ['highlight' => true]),
        );

        self::assertNotEmpty($result->hits);

        foreach ($result->hits as $hit) {
            self::assertSame(['title'], $hit->matchedFields);
            self::assertStringContainsString('Zqxwoodland', (string)$hit->getSnippet());
            self::assertStringContainsString('<mark>Zqxwoodland</mark>', (string)$hit->getHighlight());
            self::assertSame($hit->getSnippet(), $hit->getSnippet('title'));
        }
    }

    public function testHitsAndResultsAreFullyNormalized(): void
    {
        $result = $this->search();

        self::assertSame($this->index->handle, $result->indexHandle);
        self::assertSame(CraftProvider::class, $result->provider);
        self::assertSame(3, $result->total);
        self::assertSame(20, $result->limit);
        self::assertSame(0, $result->offset);
        self::assertGreaterThan(0.0, $result->executionTime);
        self::assertSame([Entry::class], $result->metadata['elementTypes']);
        self::assertCount(3, $result->getElements());

        foreach ($result->hits as $hit) {
            self::assertGreaterThan(0, $hit->elementId);
            self::assertSame($this->sectionSiteId(), $hit->siteId);
            self::assertSame(Entry::class, $hit->elementType);
            self::assertGreaterThan(0.0, $hit->score);
            self::assertInstanceOf(Entry::class, $hit->element);
            self::assertSame($hit->elementId, $hit->element->id);
            self::assertSame($hit->siteId, $hit->element->siteId);
        }
    }

    public function testElementsAreLoadedForAProviderThatOnlyIdentifiesThem(): void
    {
        $index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            BareHitProvider::class,
            $this->sectionSiteId(),
        );

        BareHitProvider::$hits = [new SearchHit([
            'elementId' => (int)$this->entries[0]->id,
            'siteId' => (int)$this->entries[0]->siteId,
            'elementType' => Entry::class,
        ])];

        $hit = $this->plugin()->getSearch()->search(SearchQuery::create($index->handle, self::TERM))->hits[0];

        self::assertInstanceOf(Entry::class, $hit->element);
        self::assertSame($this->entries[0]->id, $hit->element->id);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(array $params = []): SearchResult
    {
        return $this->plugin()->getSearch()->search(
            SearchQuery::create($this->index->handle, self::TERM, $params),
        );
    }

    /**
     * @return string[]
     */
    private function titles(SearchResult $result): array
    {
        return array_map(static fn(SearchHit $hit) => (string)$hit->element?->title, $result->hits);
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
