<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\SearchKit\errors\UnauthorizedQueryException;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\providers\CraftProvider;

/**
 * An unpublished search is authorized before it runs, never by dropping results afterwards. These
 * tests hold every page of one to the same result set, and to a total that counts all of it.
 */
class VisibilityPaginationTest extends SearchContentTestCase
{
    private const TERM = 'zqxwpaged';
    private const PAGE_SIZE = 2;

    private SearchIndex $index;

    /** @var int[] Element IDs in the order the search returns them. */
    private array $ordered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndexWithFields(
            [Entry::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
            $this->ordered[] = (int)$this->createPage("Zqxwpaged $name", enabled: false)->id;
        }

        Craft::$app->getUser()->setIdentity($this->adminUser());
    }

    public function testEveryPageAgreesOnTheSameResultSet(): void
    {
        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $result = $this->page($page);

            self::assertSame(5, $result->total, 'The total must count the whole result set on every page.');
            self::assertSame(3, $result->getPageCount());
            self::assertSame($page, $result->getPage());
            self::assertSame($page < 3, $result->getHasNextPage());
            self::assertSame($page > 1, $result->getHasPreviousPage());
            self::assertSame($page < 3 ? $page + 1 : null, $result->getNextPage());
            self::assertSame($page > 1 ? $page - 1 : null, $result->getPreviousPage());

            $seen = [...$seen, ...$this->idsOf($result)];
        }

        self::assertSame($this->ordered, $seen, 'Every result appears exactly once, in order.');
        self::assertCount(5, array_unique($seen));
    }

    public function testARefusalOnALaterPageDoesNotShrinkAnEarlierPage(): void
    {
        // The result on the last page is the one this viewer is refused.
        $this->refuseViewing($this->ordered[4]);

        $first = $this->page(1);

        self::assertSame(5, $first->total, 'A refusal elsewhere must not be subtracted from the total.');
        self::assertSame(3, $first->getPageCount());
        self::assertSame(array_slice($this->ordered, 0, 2), $this->idsOf($first));
        self::assertSame(array_slice($this->ordered, 2, 2), $this->idsOf($this->page(2)));

        $this->expectException(UnauthorizedQueryException::class);
        $this->page(3);
    }

    public function testARefusalOnThisPageIsNeverAPartialPage(): void
    {
        $this->refuseViewing($this->ordered[0]);

        $this->expectException(UnauthorizedQueryException::class);
        $this->page(1);
    }

    public function testRefusalsSpreadAcrossPagesLeaveTheRestIntact(): void
    {
        $this->refuseViewing($this->ordered[1], $this->ordered[4]);

        $second = $this->page(2);

        self::assertSame(5, $second->total);
        self::assertSame(array_slice($this->ordered, 2, 2), $this->idsOf($second));

        $refused = [];

        foreach ([1, 3] as $page) {
            try {
                $this->page($page);
            } catch (UnauthorizedQueryException) {
                $refused[] = $page;
            }
        }

        self::assertSame([1, 3], $refused, 'Each page holding a refused result is unanswerable.');
    }

    public function testAWindowAtAnyOffsetReportsTheSameResultSet(): void
    {
        $result = $this->searchIndex($this->index->handle, self::TERM, [
            'status' => 'disabled',
            'orderBy' => 'title asc',
            'limit' => 2,
            'offset' => 1,
        ]);

        self::assertSame(5, $result->total);
        self::assertSame(array_slice($this->ordered, 1, 2), $this->idsOf($result));
        self::assertTrue($result->getHasPreviousPage());
        self::assertTrue($result->getHasNextPage());
    }

    public function testSeveralElementTypesPaginateAsOneResultSet(): void
    {
        $index = $this->persistIndexWithFields(
            [Entry::class => 'title', Category::class => 'title'],
            CraftProvider::class,
            $this->fieldSectionSiteId(),
        );

        $category = $this->createCategory('Zqxwpaged Foxtrot');
        $category->enabled = false;
        self::assertTrue(Craft::$app->getElements()->saveElement($category));
        Craft::$app->getSearch()->indexElementAttributes($category);

        $expected = [...$this->ordered, (int)$category->id];
        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $result = $this->searchIndex($index->handle, self::TERM, [
                'status' => 'disabled',
                'orderBy' => 'title asc',
                'limit' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            self::assertSame(6, $result->total);
            self::assertSame(3, $result->getPageCount());
            $seen = [...$seen, ...$this->idsOf($result)];
        }

        self::assertSame($expected, $seen, 'Results from both element types appear once each, in order.');
    }

    private function page(int $page): SearchResult
    {
        return $this->searchIndex($this->index->handle, self::TERM, [
            'status' => 'disabled',
            'orderBy' => 'title asc',
            'limit' => self::PAGE_SIZE,
            'page' => $page,
        ]);
    }
}
