<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchResult;

class SearchResultTest extends TestCase
{
    public function testDerivesPaginationFromTotals(): void
    {
        $result = new SearchResult([
            'total' => 45,
            'limit' => 20,
            'offset' => 20,
        ]);

        self::assertSame(2, $result->getPage());
        self::assertSame(3, $result->getPageCount());
    }

    public function testReportsHitsWithoutAssumingTotals(): void
    {
        $result = new SearchResult([
            'total' => 2,
            'hits' => [
                new SearchHit(['elementId' => 7, 'score' => 12.5]),
                new SearchHit(['elementId' => 9, 'score' => 3.0]),
            ],
        ]);

        self::assertSame(2, $result->getCount());
        self::assertSame([7, 9], $result->getElementIds());
    }

    public function testEmptyResultHasNoPages(): void
    {
        self::assertSame(0, (new SearchResult())->getPageCount());
    }
}
