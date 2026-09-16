<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Category;
use craft\elements\Entry;
use DateTime;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Covers a search whose index spans more than one element type, where Craft can only order each
 * element type's own query and SearchKit has to put the results in one order.
 */
class MultiTypeSearchTest extends SearchContentTestCase
{
    private const TERM = 'zqxwblend';

    private SearchIndex $index;

    /** @var array<string,int> Element IDs keyed by the distinctive part of their title. */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title', Category::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        // Titles interleave across the two element types, and the dates deliberately disagree with
        // both the titles and the order the elements were created in.
        $alpha = $this->createCategory('Zqxwblend Alpha');
        $bravo = $this->createPage('Zqxwblend Bravo', postDate: new DateTime('-3 hours'));
        $charlie = $this->createCategory('Zqxwblend Charlie');
        $delta = $this->createPage('Zqxwblend Delta', postDate: new DateTime('-1 hour'));

        $this->backdate($alpha, '-1 hour');
        $this->backdate($bravo, '-3 hours');
        $this->backdate($charlie, '-4 hours');
        $this->backdate($delta, '-2 hours');

        foreach (['alpha' => $alpha, 'bravo' => $bravo, 'charlie' => $charlie, 'delta' => $delta] as $name => $element) {
            $this->ids[$name] = (int)$element->id;
        }
    }

    public function testBothElementTypesAreSearchedAndCounted(): void
    {
        $result = $this->search();

        self::assertSame(4, $result->total);
        self::assertEqualsCanonicalizing(array_values($this->ids), $this->idsOf($result));
        self::assertEqualsCanonicalizing(
            [Entry::class, Category::class],
            array_unique(array_map(static fn(SearchHit $hit) => (string)$hit->elementType, $result->hits)),
        );
    }

    public function testResultsAreOrderedAcrossElementTypes(): void
    {
        self::assertSame(
            [$this->ids['alpha'], $this->ids['bravo'], $this->ids['charlie'], $this->ids['delta']],
            $this->idsOf($this->search(['orderBy' => 'title asc'])),
        );

        self::assertSame(
            [$this->ids['delta'], $this->ids['charlie'], $this->ids['bravo'], $this->ids['alpha']],
            $this->idsOf($this->search(['orderBy' => 'title desc'])),
        );
    }

    public function testOrderingByADateWorksAcrossElementTypes(): void
    {
        $oldestFirst = [$this->ids['charlie'], $this->ids['bravo'], $this->ids['delta'], $this->ids['alpha']];

        self::assertSame($oldestFirst, $this->idsOf($this->search(['orderBy' => 'dateCreated asc'])));
        self::assertSame(array_reverse($oldestFirst), $this->idsOf($this->search(['orderBy' => 'dateCreated desc'])));
    }

    public function testRelevanceOrderingStillRanksHighestFirst(): void
    {
        $scores = array_map(static fn(SearchHit $hit) => $hit->score, $this->search()->hits);

        $sorted = $scores;
        rsort($sorted);

        self::assertSame($sorted, $scores);
    }

    public function testPaginationWalksEveryResultExactlyOnce(): void
    {
        $seen = [];

        for ($page = 1; $page <= 4; $page++) {
            $result = $this->search(['limit' => 1, 'page' => $page, 'orderBy' => 'title asc']);

            self::assertSame(4, $result->total);
            self::assertSame(4, $result->getPageCount());
            self::assertSame($page, $result->getPage());
            self::assertCount(1, $result->hits);

            $seen[] = $result->hits[0]->elementId;
        }

        self::assertSame(array_values($this->ids), $seen, 'Every result must appear once, in order.');
    }

    public function testAMiddleOffsetTakesTheMiddleOfTheOrdering(): void
    {
        $result = $this->search(['limit' => 2, 'offset' => 1, 'orderBy' => 'title asc']);

        self::assertSame([$this->ids['bravo'], $this->ids['charlie']], $this->idsOf($result));
        self::assertSame(4, $result->total);
        self::assertTrue($result->getHasNextPage());
        self::assertTrue($result->getHasPreviousPage());
    }

    public function testAnOffsetBeyondTheTotalReturnsNothing(): void
    {
        $result = $this->search(['limit' => 2, 'offset' => 10]);

        self::assertSame([], $result->hits);
        self::assertSame(4, $result->total);
        self::assertFalse($result->getHasNextPage());
    }

    public function testALargerLimitReturnsEverythingOnOnePage(): void
    {
        $result = $this->search(['limit' => 50, 'orderBy' => 'title asc']);

        self::assertCount(4, $result->hits);
        self::assertSame(1, $result->getPageCount());
        self::assertFalse($result->getHasNextPage());
        self::assertFalse($result->getHasPreviousPage());
    }

    public function testAFieldOnlyOneElementTypeCanBeSortedByIsRejected(): void
    {
        try {
            $this->search(['orderBy' => 'postDate desc']);
            self::fail('A sort one element type cannot answer must be rejected, not applied to the rest.');
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('sorts', $e->getErrors());
        }
    }

    public function testASearchCanBeNarrowedToOneElementType(): void
    {
        $result = $this->search(['filters' => ['elementType' => 'category'], 'orderBy' => 'title asc']);

        self::assertSame([$this->ids['alpha'], $this->ids['charlie']], $this->idsOf($result));
        self::assertSame(2, $result->total);
    }

    public function testASingleElementTypeCanBeOrderedByCraftsOwnExpressions(): void
    {
        // Craft orders entries by post date with SQL of its own, which only one element type uses.
        $result = $this->search([
            'filters' => ['elementType' => 'entry'],
            'orderBy' => 'postDate asc',
        ]);

        self::assertSame([$this->ids['bravo'], $this->ids['delta']], $this->idsOf($result));
        self::assertSame(
            [$this->ids['delta'], $this->ids['bravo']],
            $this->idsOf($this->search(['filters' => ['elementType' => 'entry'], 'orderBy' => 'postDate desc'])),
        );
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(array $params = []): SearchResult
    {
        return $this->searchIndex($this->index->handle, self::TERM, $params);
    }
}
