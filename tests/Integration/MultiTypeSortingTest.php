<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * Ordering results drawn from more than one element type happens in PHP, so these tests hold it to
 * the cases a database would decide arbitrarily: equal values, several sort clauses, and nulls.
 */
class MultiTypeSortingTest extends SearchContentTestCase
{
    private const TERM = 'zqxwtied';

    private SearchIndex $index;

    /** @var int[] Entry IDs, in creation order. */
    private array $entries = [];

    /** @var int[] Category IDs, in creation order. */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title', Category::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        // Two entries and two categories share one title, across both element types.
        $this->entries[] = (int)$this->createPage('Zqxwtied Same')->id;
        $this->categories[] = (int)$this->createCategory('Zqxwtied Same')->id;
        $this->entries[] = (int)$this->createPage('Zqxwtied Same')->id;
        $this->categories[] = (int)$this->createCategory('Zqxwtied Same')->id;

        foreach ($this->allIds() as $id) {
            $this->backdateById($id, '-2 hours');
        }
    }

    public function testIdenticalTitlesAreOrderedDeterministically(): void
    {
        $first = $this->idsOf($this->search(['orderBy' => 'title asc']));
        $second = $this->idsOf($this->search(['orderBy' => 'title asc']));

        self::assertSame($first, $second, 'The same search must return the same order every time.');
        self::assertSame($this->sortedIds(), $first, 'Equal titles fall back to the element ID.');
    }

    public function testIdenticalDatesAcrossElementTypesAreOrderedDeterministically(): void
    {
        $result = $this->idsOf($this->search(['orderBy' => 'dateCreated asc']));

        self::assertSame($this->sortedIds(), $result);
        self::assertSame($result, $this->idsOf($this->search(['orderBy' => 'dateCreated asc'])));
    }

    public function testCompletelyEqualSortValuesStillOrderTheSameWay(): void
    {
        // Every sort clause ties for all four results, leaving only the tie-break to decide.
        $result = $this->idsOf($this->search(['orderBy' => 'title asc, dateCreated asc']));

        self::assertSame($this->sortedIds(), $result);
    }

    public function testSeveralSortClausesAreAppliedInOrder(): void
    {
        $distinct = $this->createPage('Zqxwtied Alpha');
        $this->backdateById((int)$distinct->id, '-1 hour');

        $byTitle = $this->idsOf($this->search(['orderBy' => 'title asc, dateCreated desc']));

        self::assertSame((int)$distinct->id, $byTitle[0], 'The first clause decides before the second.');
        self::assertSame($this->sortedIds(), array_slice($byTitle, 1));
    }

    public function testAscendingAndDescendingAreMirrorImagesWhenValuesDiffer(): void
    {
        $this->createPage('Zqxwtied Alpha');
        $this->createCategory('Zqxwtied Zulu');

        $ascending = $this->titlesOf($this->search(['orderBy' => 'title asc']));
        $descending = $this->titlesOf($this->search(['orderBy' => 'title desc']));

        self::assertSame('Zqxwtied Alpha', $ascending[0]);
        self::assertSame('Zqxwtied Zulu', $descending[0]);
        self::assertSame(array_reverse($ascending), array_slice($descending, 0));
    }

    public function testAPropertyThatIsNullOnSomeResultsStillOrders(): void
    {
        // Categories have no URI in this project, while the entries in a structure section do.
        $ascending = $this->idsOf($this->search(['orderBy' => 'uri asc']));
        $descending = $this->idsOf($this->search(['orderBy' => 'uri desc']));

        self::assertCount(4, $ascending);
        self::assertEqualsCanonicalizing($this->allIds(), $ascending);
        self::assertSame($ascending, $this->idsOf($this->search(['orderBy' => 'uri asc'])));
        self::assertCount(4, $descending);
    }

    public function testPaginationFollowsTheTieBreakWithoutRepeatingResults(): void
    {
        $seen = [];

        for ($page = 1; $page <= 4; $page++) {
            $result = $this->search(['orderBy' => 'title asc', 'limit' => 1, 'page' => $page]);

            self::assertSame(4, $result->total);
            self::assertCount(1, $result->hits);

            $seen[] = $result->hits[0]->elementId;
        }

        self::assertSame($this->sortedIds(), $seen, 'Tied results must page through once each, in order.');
    }

    public function testBothElementTypesAreRepresentedInTheOrdering(): void
    {
        $types = array_map(
            static fn(SearchHit $hit) => (string)$hit->elementType,
            $this->search(['orderBy' => 'title asc'])->hits,
        );

        self::assertEqualsCanonicalizing([Entry::class, Category::class], array_unique($types));
    }

    /**
     * @return int[]
     */
    private function allIds(): array
    {
        return [...$this->entries, ...$this->categories];
    }

    /**
     * @return int[] The tie-break order: element ID, ascending.
     */
    private function sortedIds(): array
    {
        $ids = $this->allIds();
        sort($ids);

        return $ids;
    }

    /**
     * @return string[]
     */
    private function titlesOf(SearchResult $result): array
    {
        return array_map(static fn(SearchHit $hit) => (string)$hit->element?->title, $result->hits);
    }

    private function backdateById(int $id, string $modifier): void
    {
        $element = \Craft::$app->getElements()->getElementById($id, null, $this->fieldSectionSiteId());

        if ($element !== null) {
            $this->backdate($element, $modifier);
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function search(array $params = []): SearchResult
    {
        return $this->searchIndex($this->index->handle, self::TERM, $params);
    }
}
