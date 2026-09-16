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
        $result = new SearchResult();

        self::assertSame(0, $result->getPageCount());
        self::assertTrue($result->isEmpty());
        self::assertFalse($result->getHasNextPage());
        self::assertNull($result->getNextPage());
    }

    public function testReportsWhereItSitsInThePages(): void
    {
        $result = new SearchResult(['total' => 45, 'limit' => 20, 'offset' => 20]);

        self::assertTrue($result->getHasNextPage());
        self::assertTrue($result->getHasPreviousPage());
        self::assertSame(3, $result->getNextPage());
        self::assertSame(1, $result->getPreviousPage());
    }

    public function testTheLastPageHasNothingAfterIt(): void
    {
        $result = new SearchResult(['total' => 45, 'limit' => 20, 'offset' => 40]);

        self::assertSame(3, $result->getPage());
        self::assertFalse($result->getHasNextPage());
        self::assertNull($result->getNextPage());
        self::assertSame(2, $result->getPreviousPage());
    }

    public function testAHitOffersItsHeaviestExcerptFirst(): void
    {
        $hit = new SearchHit([
            'elementId' => 7,
            'snippets' => ['title' => 'Winter boots', 'body' => 'Boots for winter'],
            'highlights' => ['title' => '<mark>Winter</mark> boots', 'body' => 'Boots for <mark>winter</mark>'],
        ]);

        self::assertSame('Winter boots', $hit->getSnippet());
        self::assertSame('Boots for winter', $hit->getSnippet('body'));
        self::assertSame('<mark>Winter</mark> boots', (string)$hit->getHighlight());
        self::assertNull($hit->getSnippet('missing'));
        self::assertNull($hit->getHighlight('missing'));
    }

    public function testAHitWithoutExcerptsOffersNothing(): void
    {
        $hit = new SearchHit(['elementId' => 7]);

        self::assertFalse($hit->hasHighlights());
        self::assertNull($hit->getSnippet());
        self::assertNull($hit->getHighlight());
    }
}
